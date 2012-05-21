<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Url;
use Guzzle\Service\Inspector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * OAuth signing plugin
 * @link http://oauth.net/core/1.0/#rfc.section.9.1.1
 */
class OauthPlugin implements EventSubscriberInterface
{
    /**
     * @var Collection Configuration settings
     */
    protected $config;

    /**
     * Create a new OAuth 1.0 plugin
     *
     * @param array $config Configuration array containing these parameters:
     *     - string 'consumer_key'     Consumer key
     *     - string 'consumer_secret'  Consumer secret
     *     - string 'token'            Token
     *     - string 'token_secret'     Token secret
     *     - string 'version'          OAuth version.  Defaults to 1.0
     *     - string 'signature_method' Custom signature method
     *     - array|Closure 'signature_callback' Custom signature callback that accepts a string to sign and a signing key
     */
    public function __construct($config)
    {
        $this->config = Inspector::prepareConfig($config, array(
            'version' => '1.0',
            'consumer_key' => 'anonymous',
            'consumer_secret' => 'anonymous',
            'signature_method' => 'HMAC-SHA1',
            'signature_callback' => function($stringToSign, $key) {
                return hash_hmac('sha1', $stringToSign, $key, true);
            }
        ), array(
            'signature_method', 'signature_callback', 'version',
            'consumer_key', 'consumer_secret', 'token', 'token_secret'
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
     */
    public function onRequestBeforeSend(Event $event)
    {
        $timestamp = $event['timestamp'] ?: time();

        // Build Authorization header
        $authString = 'OAuth ';
        foreach(array(
            'oauth_consumer_key'     => $this->config['consumer_key'],
            'oauth_nonce'            => $this->generateNonce($event['request'], $timestamp),
            'oauth_signature'        => $this->getSignature($event['request'], $timestamp),
            'oauth_signature_method' => $this->config['signature_method'],
            'oauth_timestamp'        => $timestamp,
            'oauth_token'            => $this->config['token'],
            'oauth_version'          => $this->config['version'],
        ) as $key => $val) {
            $authString .= $key . '="' . urlencode($val) . '", ';
        }

        // Add Authorization header
        $event['request']->setHeader('Authorization', substr($authString, 0, -2));
    }

    /**
     * Calculate signature for request
     *
     * @param RequestInterface $request   Request to generate a signature for
     * @param int              $timestamp Timestamp to use for nonce
     *
     * @return string
     */
    public function getSignature(RequestInterface $request, $timestamp)
    {
        $string = $this->getStringToSign($request, $timestamp);
        $key = urlencode($this->config['consumer_secret']) . '&' . urlencode($this->config['token_secret']);

        return base64_encode(call_user_func($this->config['signature_callback'], $string, $key));
    }

    /**
     * Calculate string to sign
     *
     * @param RequestInterface $request   Request to generate a signature for
     * @param int              $timestamp Timestamp to use for nonce
     *
     * @return string
     */
    public function getStringToSign(RequestInterface $request, $timestamp)
    {
        $params = new Collection(array(
            'oauth_consumer_key'     => $this->config['consumer_key'],
            'oauth_nonce'            => $this->generateNonce($request, $timestamp),
            'oauth_signature_method' => $this->config['signature_method'],
            'oauth_timestamp'        => $timestamp,
            'oauth_token'            => $this->config['token'],
            'oauth_version'          => $this->config['version']
        ));

        // Add query string parameters
        $params->merge($request->getQuery());
        // Add POST fields to signing string
        if ($request instanceof EntityEnclosingRequestInterface && $request->getHeader('Content-Type') == 'application/x-www-form-urlencoded') {
            $params->merge($request->getPostFields());
        }

        // Sort params
        $params = $params->getAll();
        ksort($params);

        // Build signing string from combined params
        $parameterString = array();
        foreach ($params as $key => $values) {
            $key = rawurlencode($key);
            $values = (array) $values;
            sort($values);
            foreach ($values as $value) {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $parameterString[] = $key . '=' . rawurlencode($value);
            }
        }

        $url = Url::factory($request->getUrl())->setQuery('')->setFragment('');

        return strtoupper($request->getMethod()) . '&'
             . rawurlencode($url) . '&'
             . rawurlencode(implode('&', $parameterString));
    }

    /**
     * Returns a Nonce Based on the Timestamp and URL.  This will allow for
     * multiple requests in parallel with the same exact timestamp to use
     * separate nonce's.
     *
     * @param RequestInterface $request   Request to generate a nonce for
     * @param int              $timestamp Timestamp to use for nonce
     *
     * @return string
     */
    protected function generateNonce(RequestInterface $request, $timestamp)
    {
        return sha1($timestamp . $request->getUrl());
    }
}
