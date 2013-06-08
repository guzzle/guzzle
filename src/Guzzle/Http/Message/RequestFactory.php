<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Collection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Url;
use Guzzle\Parser\ParserRegistry;
use Guzzle\Plugin\Log\LogPlugin;
use Guzzle\Http\RedirectPlugin;

/**
 * Default HTTP request factory used to create the default {@see Request} and {@see EntityEnclosingRequest} objects.
 */
class RequestFactory implements RequestFactoryInterface
{
    /** @var RequestFactory Singleton instance of the default request factory */
    protected static $instance;

    /** @var string Class to instantiate for requests with no body */
    protected $requestClass = 'Guzzle\\Http\\Message\\Request';

    /** @var string Class to instantiate for requests with a body */
    protected $entityEnclosingRequestClass = 'Guzzle\\Http\\Message\\EntityEnclosingRequest';

    /**
     * Get a cached instance of the default request factory
     *
     * @return RequestFactory
     */
    public static function getInstance()
    {
        // @codeCoverageIgnoreStart
        if (!static::$instance) {
            static::$instance = new static();
        }
        // @codeCoverageIgnoreEnd

        return static::$instance;
    }

    public function fromMessage($message)
    {
        $parsed = ParserRegistry::getInstance()->getParser('message')->parseRequest($message);

        if (!$parsed) {
            return false;
        }

        $request = $this->fromParts($parsed['method'], $parsed['request_url'],
            $parsed['headers'], $parsed['body'], $parsed['protocol'],
            $parsed['version']);

        // EntityEnclosingRequest adds an "Expect: 100-Continue" header when using a raw request body for PUT or POST
        // requests. This factory method should accurately reflect the message, so here we are removing the Expect
        // header if one was not supplied in the message.
        if (!isset($parsed['headers']['Expect']) && !isset($parsed['headers']['expect'])) {
            $request->removeHeader('Expect');
        }

        return $request;
    }

    public function fromParts(
        $method,
        array $urlParts,
        $headers = null,
        $body = null,
        $protocol = 'HTTP',
        $protocolVersion = '1.1'
    ) {
        return $this->create($method, Url::buildUrl($urlParts), $headers, $body)
                    ->setProtocolVersion($protocolVersion);
    }

    public function create($method, $url, $headers = null, $body = null, array $options = array())
    {
        $method = strtoupper($method);

        if ($method == 'GET' || $method == 'HEAD' || $method == 'TRACE' || $method == 'OPTIONS') {
            // Handle non-entity-enclosing request methods
            $request = new $this->requestClass($method, $url, $headers);
            if ($body) {
                // The body is where the response body will be stored
                $type = gettype($body);
                if ($type == 'string' || $type == 'resource' || $type == 'object') {
                    $request->setResponseBody($body);
                }
            }
        } else {
            // Create an entity enclosing request by default
            $request = new $this->entityEnclosingRequestClass($method, $url, $headers);
            if ($body) {
                // Add POST fields and files to an entity enclosing request if an array is used
                if (is_array($body) || $body instanceof Collection) {
                    // Normalize PHP style cURL uploads with a leading '@' symbol
                    foreach ($body as $key => $value) {
                        if (is_string($value) && substr($value, 0, 1) == '@') {
                            $request->addPostFile($key, $value);
                            unset($body[$key]);
                        }
                    }
                    // Add the fields if they are still present and not all files
                    $request->addPostFields($body);
                } else {
                    // Add a raw entity body body to the request
                    $request->setBody($body, (string) $request->getHeader('Content-Type'));
                    if ((string) $request->getHeader('Transfer-Encoding') == 'chunked') {
                        $request->removeHeader('Content-Length');
                    }
                }
            }
        }

        if ($options) {
            $this->applyOptions($request, $options);
        }

        return $request;
    }

    /**
     * Clone a request while changing the method. Emulates the behavior of
     * {@see Guzzle\Http\Message\Request::clone}, but can change the HTTP method.
     *
     * @param RequestInterface $request Request to clone
     * @param string           $method  Method to set
     *
     * @return RequestInterface
     */
    public function cloneRequestWithMethod(RequestInterface $request, $method)
    {
        // Create the request with the same client if possible
        if ($client = $request->getClient()) {
            $cloned = $request->getClient()->createRequest($method, $request->getUrl(), $request->getHeaders());
        } else {
            $cloned = $this->create($method, $request->getUrl(), $request->getHeaders());
        }

        $cloned->getCurlOptions()->replace($request->getCurlOptions()->getAll());
        $cloned->setEventDispatcher(clone $request->getEventDispatcher());
        // Ensure that that the Content-Length header is not copied if changing to GET or HEAD
        if (!($cloned instanceof EntityEnclosingRequestInterface)) {
            $cloned->removeHeader('Content-Length');
        } elseif ($request instanceof EntityEnclosingRequestInterface) {
            $cloned->setBody($request->getBody());
        }
        $cloned->getParams()->replace($request->getParams()->getAll());
        $cloned->dispatch('request.clone', array('request' => $cloned));

        return $cloned;
    }

    public function applyOptions(RequestInterface $request, array $options = array())
    {
        $methods = get_class_methods(__CLASS__);
        // Iterate over each key value pair and attempt to apply a config using function visitors
        foreach ($options as $key => $value) {
            $method = "visit_{$key}";
            if (in_array($method, $methods)) {
                $this->{$method}($request, $value);
            }
        }
    }

    protected function visit_headers(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('headers value must be an array');
        }

        $request->addHeaders($value);
    }

    protected function visit_body(RequestInterface $request, $value)
    {
        if ($request instanceof EntityEnclosingRequestInterface) {
            $request->setBody($value);
        } else {
            throw new InvalidArgumentException('Attempting to set a body on a non-entity-enclosing request');
        }
    }

    protected function visit_allow_redirects(RequestInterface $request, $value)
    {
        if ($value === false) {
            $request->getParams()->set(RedirectPlugin::DISABLE, true);
        }
    }

    protected function visit_auth(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('auth value must be an array');
        }

        $authType = isset($value[2]) ? $value[2] : 'basic';

        $request->setAuth($value[0], isset($value[1]) ? $value[1] : null, $authType);
    }

    protected function visit_query(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('query value must be an array');
        }

        $request->getQuery()->overwriteWith($value);
    }

    protected function visit_cookies(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('cookies value must be an array');
        }

        foreach ($value as $name => $v) {
            $request->addCookie($name, $v);
        }
    }

    protected function visit_timeout(RequestInterface $request, $value)
    {
        $request->getCurlOptions()->set(CURLOPT_TIMEOUT_MS, $value * 1000);
    }

    protected function visit_debug(RequestInterface $request, $value)
    {
        if (class_exists('Guzzle\Plugin\Log\LogPlugin')) {
            $request->addSubscriber(LogPlugin::getDebugPlugin());
        } else {
            // @codeCoverageIgnoreStart
            $request->getCurlOptions()->set(CURLOPT_VERBOSE, true);
            // @codeCoverageIgnoreEnd
        }
    }

    protected function visit_events(RequestInterface $request, $value)
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

    protected function visit_plugins(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('plugins value must be an array');
        }

        foreach ($value as $plugin) {
            $request->addSubscriber($plugin);
        }
    }

    protected function visit_verify(RequestInterface $request, $value)
    {
        $curl = $request->getCurlOptions();
        if ($value === true || is_string($value)) {
            $curl[CURLOPT_SSL_VERIFYHOST] = 2;
            $curl[CURLOPT_SSL_VERIFYPEER] = true;
            if ($value !== true) {
                $curl[CURLOPT_CAINFO] = $value;
            }
        } elseif ($value === false) {
            unset($curl[CURLOPT_CAINFO]);
            $curl[CURLOPT_SSL_VERIFYHOST] = 0;
            $curl[CURLOPT_SSL_VERIFYPEER] = false;
        }
    }

    protected function visit_exceptions(RequestInterface $request, $value)
    {
        if ($value === false || $value === 0) {
            $dispatcher = $request->getEventDispatcher();
            foreach ($dispatcher->getListeners('request.error') as $listener) {
                if ($listener[0] == 'Guzzle\Http\Message\Request' && $listener[1] = 'onRequestError') {
                    $dispatcher->removeListener('request.error', $listener);
                    break;
                }
            }
        }
    }

    protected function visit_save_to(RequestInterface $request, $value)
    {
        $request->setResponseBody($value);
    }

    protected function visit_proxy(RequestInterface $request, $value)
    {
        $request->getCurlOptions()->set(CURLOPT_PROXY, $value);
    }

    protected function visit_connect_timeout(RequestInterface $request, $value)
    {
        $request->getCurlOptions()->set(CURLOPT_CONNECTTIMEOUT_MS, $value * 1000);
    }
}
