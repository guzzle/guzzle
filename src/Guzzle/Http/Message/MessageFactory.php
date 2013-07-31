<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\RedirectPlugin;
use Guzzle\Plugin\Log\LogPlugin;
use Guzzle\Url\Url;

/**
 * Default HTTP request factory used to create {@see Request} and {@see Response} objects
 */
class MessageFactory implements MessageFactoryInterface
{
    /** @var MessageFactory Singleton instance of the default request factory */
    private static $instance;

    /**
     * Get a cached instance of the default request factory
     *
     * @return MessageFactory
     */
    public static function getInstance()
    {
        if (!static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    public function createResponse()
    {
        return new Response();
    }

    public function createRequest($method, $url, $body = null, array $options = array())
    {
        $headers = isset($options['headers']) ? $options['headers'] : array();
        $request = new Request($method, $url, $headers);

        if ($body) {
            // Add POST fields and files to an entity enclosing request if an array is used
            if (is_array($body)) {
                // Normalize PHP style cURL uploads with a leading '@' symbol
                foreach ($body as $key => $value) {
                    if (is_string($value) && substr($value, 0, 1) == '@') {
                        // $request->addPostFile($key, $value);
                        unset($body[$key]);
                    }
                }
                // Add the fields if they are still present and not all files
                // $request->addPostFields($body);
            } else {
                // Add a raw entity body body to the request
                $request->setBody($body, (string) $request->getHeader('Content-Type'));
                if ((string) $request->getHeader('Transfer-Encoding') == 'chunked') {
                    $request->removeHeader('Content-Length');
                }
            }
        }

        if ($options) {
            $this->applyOptions($request, $options);
        }

        return $request;
    }

    /**
     * Create a request using an array returned from a MessageParserInterface
     *
     * @param array $parsed Parsed request data
     *
     * @return RequestInterface
     */
    public function fromParsedRequest(array $parsed)
    {
        $request = $this->createRequest(
            $parsed['method'],
            Url::buildUrl($parsed['request_url']),
            $parsed['headers'],
            $parsed['body']
        )->setProtocolVersion($parsed['version']);

        // "Expect: 100-Continue" header is added when using a raw request body for PUT or POST requests.
        // This factory method should accurately reflect the message, so here we are removing the Expect
        // header if one was not supplied in the message.
        if (!isset($parsed['headers']['Expect']) && !isset($parsed['headers']['expect'])) {
            $request->removeHeader('Expect');
        }

        return $request;
    }

    protected function applyOptions(RequestInterface $request, array $options = array())
    {
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        // Iterate over each key value pair and attempt to apply a config using function visitors
        foreach ($options as $key => $value) {
            $method = "visit_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $value);
            }
        }
    }

    private function visit_allow_redirects(RequestInterface $request, $value)
    {
        if ($value === false) {

        }
    }

    private function visit_auth(RequestInterface $request, $value)
    {
        if (!is_array($value) || count($value) < 2) {
            throw new \InvalidArgumentException(
                'auth value must be an array that contains a username, password, and optional authentication scheme'
            );
        }
    }

    private function visit_query(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('query value must be an array');
        }

        $request->getQuery()->overwriteWith($value);
    }

    private function visit_cookies(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('cookies value must be an array');
        }

        foreach ($value as $name => $v) {
            $request->addHeader('Cookie')->add("{$name}={$v}");
        }
    }

    private function visit_events(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('events value must be an array');
        }

        foreach ($value as $name => $method) {
            if (is_array($method)) {
                $request->getEventDispatcher()->addListener($name, $method[0], $method[1]);
            } else {
                $request->getEventDispatcher()->addListener($name, $method);
            }
        }
    }

    private function visit_plugins(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('plugins value must be an array');
        }

        foreach ($value as $plugin) {
            $request->addSubscriber($plugin);
        }
    }

    private function visit_exceptions(RequestInterface $request, $value)
    {
        if ($value === false || $value === 0) {

        }
    }

    private function visit_save_to(RequestInterface $request, $value)
    {
        $request->getTransferOptions()->set('save_to', $value);
    }

    private function visit_debug(RequestInterface $request, $value)
    {

    }
}
