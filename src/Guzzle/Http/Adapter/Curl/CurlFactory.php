<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Adapter\TransactionInterface;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Stream\Stream;

/**
 * Creates curl resources from a request and response object
 */
class CurlFactory
{
    public function createHandle(TransactionInterface $transaction, MessageFactoryInterface $messageFactory)
    {
        $request = $transaction->getRequest();
        $mediator = new RequestMediator($transaction, $messageFactory);
        $options = $this->getDefaultOptions($request, $mediator);
        $this->applyMethod($request, $options);
        $this->applyTransferOptions($request, $mediator, $options);
        $this->applyHeaders($request, $options);
        unset($options['_headers']);

        // Add adapter options from the request's configuration options
        if ($config = $request->getConfig()['curl']) {
            $options = $this->applyCustomCurlOptions($config, $options);
        }

        $handle = curl_init();
        curl_setopt_array($handle, $options);

        return $handle;
    }

    protected function getDefaultOptions(RequestInterface $request, RequestMediator $mediator)
    {
        $url = $request->getUrl();

        // Strip fragment from URL. See: https://github.com/guzzle/guzzle/issues/453
        if (($pos = strpos($url, '#')) !== false) {
            $url = substr($url, 0, $pos);
        }

        $config = $request->getConfig();
        $options = array(
            CURLOPT_URL            => $url,
            CURLOPT_CONNECTTIMEOUT => $config['connect_timeout'] ?: 150,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER         => false,
            CURLOPT_WRITEFUNCTION  => array($mediator, 'writeResponseBody'),
            CURLOPT_HEADERFUNCTION => array($mediator, 'receiveResponseHeader'),
            CURLOPT_READFUNCTION   => array($mediator, 'readRequestBody'),
            CURLOPT_HTTP_VERSION   => $request->getProtocolVersion() === '1.0'
                ? CURL_HTTP_VERSION_1_0 : CURL_HTTP_VERSION_1_1,
            CURLOPT_SSL_VERIFYPEER => 1,
            CURLOPT_SSL_VERIFYHOST => 2,
            '_headers'             => $request->getHeaders()
        );

        if (defined('CURLOPT_PROTOCOLS')) {
            // Allow only HTTP and HTTPS protocols
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        // Add CURLOPT_ENCODING if Accept-Encoding header is provided
        if ($request->hasHeader('Accept-Encoding')) {
            $options[CURLOPT_ENCODING] = $request->getHeader('Accept-Encoding');
            // Let cURL set the Accept-Encoding header, prevents duplicate values
            $this->removeHeader('Accept-Encoding', $options);
        }

        return $options;
    }

    protected function applyMethod(RequestInterface $request, array &$options)
    {
        $method = $request->getMethod();
        if ($method == 'GET') {
            $options[CURLOPT_HTTPGET] = true;
            unset($options[CURLOPT_READFUNCTION]);
        } elseif ($method == 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
            unset($options[CURLOPT_WRITEFUNCTION]);
            unset($options[CURLOPT_READFUNCTION]);
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if (!$request->getBody()) {
                unset($options[CURLOPT_READFUNCTION]);
            } else {
                $this->applyBody($request, $options);
            }
        }
    }

    protected function applyBody(RequestInterface $request, array &$options)
    {
        if ($request->hasHeader('Content-Length')) {
            $size = (int) $request->getHeader('Content-Length');
        } else {
            $size = null;
        }

        $request->getBody()->seek(0);

        // You can send the body as a string using curl's CURLOPT_POSTFIELDS
        if (($size !== null && $size < 32768) || isset($request->getConfig()['curl']['body_as_string'])) {
            $options[CURLOPT_POSTFIELDS] = $request->getBody()->getContents();
            // Don't duplicate the Content-Length header
            $this->removeHeader('Content-Length', $options);
            $this->removeHeader('Transfer-Encoding', $options);
        } else {
            $options[CURLOPT_UPLOAD] = true;
            // Let cURL handle setting the Content-Length header
            if ($size !== null) {
                $options[CURLOPT_INFILESIZE] = $size;
                $this->removeHeader('Content-Length', $options);
            }
        }

        // If the Expect header is not present, prevent curl from adding it
        if (!$request->hasHeader('Expect')) {
            $options[CURLOPT_HTTPHEADER][] = 'Expect:';
        }
    }

    protected function applyHeaders(RequestInterface $request, array &$options)
    {
        foreach ($options['_headers'] as $name => $values) {
            $options[CURLOPT_HTTPHEADER][] = $name . ': ' . implode(', ', $values);
        }

        // Remove the Expect header if one was not set
        if (!$request->hasHeader('Accept')) {
            $options[CURLOPT_HTTPHEADER][] = 'Accept:';
        }
    }

    protected function applyTransferOptions(RequestInterface $request, RequestMediator $mediator, array &$options)
    {
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        foreach ($request->getConfig()->toArray() as $key => $value) {
            $method = "visit_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $mediator, $options, $value);
            }
        }
    }

    protected function visit_debug(RequestInterface $request, RequestMediator $mediator, &$options, $value)
    {
        if ($value) {
            $options[CURLOPT_VERBOSE] = true;
            $options[CURLOPT_STDERR] = is_resource($value) ? $value : fopen('php://output', 'w');
        }
    }

    protected function visit_proxy(RequestInterface $request, RequestMediator $mediator, &$options, $value)
    {
        $options[CURLOPT_PROXY] = $value;
    }

    protected function visit_timeout(RequestInterface $request, RequestMediator $mediator, &$options, $value)
    {
        $options[CURLOPT_TIMEOUT_MS] = $value * 1000;
    }

    protected function visit_connect_timeout(RequestInterface $request, RequestMediator $mediator, &$options, $value)
    {
        $options[CURLOPT_CONNECTTIMEOUT_MS] = $value * 1000;
    }

    protected function visit_verify(RequestInterface $request, RequestMediator $mediator, &$options, $value)
    {
        if ($value === false) {
            unset($options[CURLOPT_CAINFO]);
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
            $options[CURLOPT_SSL_VERIFYPEER] = false;
        } elseif ($value === true || is_string($value)) {
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            if ($value !== true) {
                if (!file_exists($value)) {
                    throw new \RuntimeException("SSL certificate authority file not found: {$value}");
                }
                $options[CURLOPT_CAINFO] = $value;
            }
        }
    }

    protected function visit_cert(RequestInterface $request, RequestMediator $mediator, &$options, $value)
    {
        if (!file_exists($value)) {
            throw new \RuntimeException("SSL certificate not found: {$value}");
        }

        $options[CURLOPT_SSLCERT] = $value;
    }

    protected function visit_ssl_key(RequestInterface $request, RequestMediator $mediator, &$options, $value)
    {
        if (is_array($value)) {
            $options[CURLOPT_SSLKEYPASSWD] = $value[1];
            $value = $value[0];
        }

        if (!file_exists($value)) {
            throw new \RuntimeException("SSL private key not found: {$value}");
        }

        $options[CURLOPT_SSLKEY] = $value;
    }

    protected function visit_auth(RequestInterface $request, RequestMediator $mediator, &$options, $value)
    {
        static $authMap = array(
            'basic'  => CURLAUTH_BASIC,
            'digest' => CURLAUTH_DIGEST,
            'ntlm'   => CURLAUTH_NTLM,
            'any'    => CURLAUTH_ANY
        );

        if (!is_array($value) || !isset($value[0]) || !isset($value[1])) {
            throw new \InvalidArgumentException('auth must be an array that contains a username and password');
        }

        $scheme = isset($value[2]) ? strtolower($value[2]) : 'basic';
        if (!isset($authMap[$scheme])) {
            throw new \InvalidArgumentException('Invalid authentication scheme: ' . $scheme);
        }

        $scheme = $authMap[$scheme];
        $options[CURLOPT_HTTPAUTH] = $scheme;
        $options[CURLOPT_USERPWD] = $value[0] . ':' . $value[1];
    }

    protected function visit_save_to(RequestInterface $request, RequestMediator $mediator, &$options, $value)
    {
        $saveTo = is_string($value) ? Stream::factory(fopen($value, 'w')) : Stream::factory($value);
        $mediator->setResponseBody($saveTo);
    }

    /**
     * Takes an array of curl options specified in the 'curl' option of a
     * request's configuration array and maps them to CURLOPT_* options.
     *
     * This method is only called when a  request has a 'curl' config setting.
     * Array key strings that start with CURL that have a matching constant
     * value will be automatically converted to the matching constant.
     *
     * @param array $config  Configuration array of custom curl option
     * @param array $options Array of existing curl options
     *
     * @return array Returns a new array of curl options
     */
    protected function applyCustomCurlOptions(array $config, array $options)
    {
        unset($config['body_as_string']);
        $curlOptions = [];

        // Map curl constant strings to defined values
        foreach ($config as $key => $value) {
            if (defined($key) && substr($key, 0, 4) === 'CURL') {
                $key = constant($key);
            }
            $curlOptions[$key] = $value;
        }

        return $curlOptions + $options;
    }

    /**
     * Remove a header from the options array
     *
     * @param string $name    Case-insensitive header to remove
     * @param array  $options Array of options to modify
     */
    private function removeHeader($name, array &$options)
    {
        foreach (array_keys($options['_headers']) as $key) {
            if (!strcasecmp($key, $name)) {
                unset($options['_headers'][$key]);
                return;
            }
        }
    }
}
