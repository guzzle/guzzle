<?php

namespace Guzzle\Plugin\Wsse;

use Guzzle\Common\Event;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\RequestInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * WSSE signing plugin
 * @link http://www.xml.com/pub/a/2003/12/17/dive.html
 */
class WssePlugin implements EventSubscriberInterface
{
    protected $config;

    /**
     * Create a new WssePlugin
     * @param array $config Configuration array containing these parameters:
     *     - string           'username'           Username required by the WSSE auth
     *     - string           'password'           Password required by the WSSE auth
     *     - callable         'timestamp_callback' (Optional) Callback that must
     *      returns a \DateTime instance
     *     - callable|Closure 'nonce_timestamp'    (Optional) Callback that must
     *      return a unique nonce
     */
    public function __construct($config)
    {
        $this->config = Collection::fromConfig($config, array(
            'timestamp_callback' => function (Event $event) {
                $date = new \DateTime();
                if ($event['timestamp']) {
                    $date->setTimestamp($event['timestamp']);
                }

                return $date;
            },
            'nonce_callback' => function (Event $event) {
                return $this->generateNonce($event['request']);
            }
        ), array(
            'username',
            'password',
            'nonce_callback',
            'timestamp_callback'
        ));
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'request.before_send' => array('onRequestBeforeSend', -1000)
        );
    }

    /**
     * Request before-send event handler
     *
     * @param Event $event Event received
     * @return array
     * @throws \InvalidArgumentException
     */
    public function onRequestBeforeSend(Event $event)
    {
        // Nonce validation
        if (! $this->isCallable($this->config['nonce_callback'])) {
            throw new \InvalidArgumentException('Option nonce_callback must be a callable or a Closure');
        }

        $nonce = call_user_func($this->config['nonce_callback'], $event);

        if (! is_string($nonce) || '' == $nonce) {
            throw new \InvalidArgumentException('Option nonce_callback must return an non empty string');
        }

        // Timestamp validation
        if (! $this->isCallable($this->config['timestamp_callback'])) {
            throw new \InvalidArgumentException('Option timestamp_callback must be a callable or a Closure');
        }

        $timestamp = call_user_func($this->config['timestamp_callback'], $event);

        if (! $timestamp instanceof \DateTime) {
            throw new \InvalidArgumentException('Option timestamp_callback must return a \DateTime instance');
        }

        $username = $this->config['username'];
        $password = $this->config['password'];
        $timestamp = $timestamp->format('c'); // format ISO 8601
        $request = $event['request'];
        $request->setHeader(
            'X-WSSE',
            $this->createWsseHeader($username, $password, $nonce, $timestamp)
        );
    }

    /**
     * Create the WSSE header
     *
     * @param  string $username
     * @param  string $password
     * @param  string $nonce
     * @param  string $timestamp
     * @return string
     */
    public function createWsseHeader($username, $password, $nonce, $timestamp)
    {
        return sprintf(
            'UsernameToken Username="%s", PasswordDigest="%s", Nonce="%s", Created="%s"',
            $username,
            $this->digest($password, $nonce, $timestamp),
            $nonce,
            $timestamp
        );
    }

    /**
     * Create a password digest
     *
     * @param  string $password
     * @param  string $nonce
     * @param  string $timestamp
     * @return string
     */
    public function digest($password, $nonce, $timestamp)
    {
        return base64_encode(sha1($nonce.$timestamp.$password, true));
    }

    /**
     * Helper to detect full callable
     *
     * @param  mixed $callback
     * @return boolean
     */
    protected function isCallable($callback)
    {
        return is_callable($callback) || $callback instanceof \Closure;
    }

    /**
     * Generate a unique nonce
     *
     * @param  RequestInterface $request
     * @return string
     */
    protected function generateNonce(RequestInterface $request)
    {
        return sha1(uniqid('', true) . $request->getUrl());
    }
}
