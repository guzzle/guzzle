<?php

namespace GuzzleHttp\Message;

use GuzzleHttp\Post\PostFileInterface;
use GuzzleHttp\Subscriber\Cookie;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Subscriber\HttpError;
use GuzzleHttp\Post\PostBody;
use GuzzleHttp\Post\PostFile;
use GuzzleHttp\Subscriber\Redirect;
use GuzzleHttp\Stream;
use GuzzleHttp\Query;
use GuzzleHttp\Url;

/**
 * Default HTTP request factory used to create Request and Response objects.
 */
class MessageFactory implements MessageFactoryInterface
{
    /** @var HttpError */
    private $errorPlugin;

    /** @var Redirect */
    private $redirectPlugin;

    public function __construct()
    {
        $this->errorPlugin = new HttpError();
        $this->redirectPlugin = new Redirect();
    }

    public function createResponse(
        $statusCode,
        array $headers = [],
        $body = null,
        array $options = []
    ) {
        if (null !== $body) {
            $body = Stream\create($body);
        }

        return new Response($statusCode, $headers, $body, $options);
    }

    public function createRequest($method, $url, array $options = [])
    {
        // Handle the request protocol version option that needs to be
        // specified in the request constructor.
        if (isset($options['version'])) {
            $options['config']['protocol_version'] = $options['version'];
            unset($options['version']);
        }

        $request = new Request($method, $url, [], null,
            isset($options['config']) ? $options['config'] : []);

        unset($options['config']);

        // Use a POST body by default
        if ($method == 'POST' && !isset($options['body'])) {
            $options['body'] = [];
        }

        if ($options) {
            $this->applyOptions($request, $options);
        }

        return $request;
    }

    /**
     * Create a request or response object from an HTTP message string
     *
     * @param string $message Message to parse
     *
     * @return RequestInterface|ResponseInterface
     * @throws \InvalidArgumentException if unable to parse a message
     */
    public function fromMessage($message)
    {
        static $parser;
        if (!$parser) {
            $parser = new MessageParser();
        }

        // Parse a response
        if (strtoupper(substr($message, 0, 4)) == 'HTTP') {
            $data = $parser->parseResponse($message);
            return $this->createResponse(
                $data['code'],
                $data['headers'],
                $data['body'] === '' ? null : $data['body'],
                $data
            );
        }

        // Parse a request
        if (!($data = ($parser->parseRequest($message)))) {
            throw new \InvalidArgumentException('Unable to parse request');
        }

        return $this->createRequest(
            $data['method'],
            Url::buildUrl($data['request_url']),
            [
                'headers' => $data['headers'],
                'body' => $data['body'] === '' ? null : $data['body'],
                'config' => [
                    'protocol_version' => $data['protocol_version']
                ]
            ]
        );
    }

    /**
     * Apply POST fields and files to a request to attempt to give an accurate
     * representation.
     *
     * @param RequestInterface $request Request to update
     * @param array            $body    Body to apply
     */
    protected function addPostData(RequestInterface $request, array $body)
    {
        $post = new PostBody();
        foreach ($body as $key => $value) {
            if (is_string($value) || is_array($value)) {
                $post->setField($key, $value);
            } elseif ($value instanceof PostFileInterface) {
                $post->addFile($value);
            } else {
                $post->addFile(new PostFile($key, $value));
            }
        }

        $request->setBody($post);
        $post->applyRequestHeaders($request);
    }

    protected function applyOptions(
        RequestInterface $request,
        array $options = []
    ) {
        // Values specified in the config map are passed to request options
        static $configMap = ['connect_timeout' => 1, 'timeout' => 1,
            'verify' => 1, 'ssl_key' => 1, 'cert' => 1, 'proxy' => 1,
            'debug' => 1, 'save_to' => 1, 'stream' => 1, 'expect' => 1];
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        // Iterate over each key value pair and attempt to apply a config using
        // double dispatch.
        $config = $request->getConfig();
        foreach ($options as $key => $value) {
            $method = "add_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $value);
            } elseif (isset($configMap[$key])) {
                $config[$key] = $value;
            } else {
                throw new \InvalidArgumentException("No method is configured "
                    . "to handle the {$key} config key");
            }
        }
    }

    private function add_body(RequestInterface $request, $value)
    {
        if ($value !== null) {
            if (is_array($value)) {
                $this->addPostData($request, $value);
            } else {
                $request->setBody(Stream\create($value));
            }
        }
    }

    private function add_allow_redirects(RequestInterface $request, $value)
    {
        static $defaultRedirect = [
            'max'     => 5,
            'strict'  => false,
            'referer' => false
        ];

        if ($value === false) {
            return;
        }

        if ($value === true) {
            $value = $defaultRedirect;
        } elseif (!isset($value['max'])) {
            throw new \InvalidArgumentException('allow_redirects must be '
                . 'true, false, or an array that contains the \'max\' key');
        } else {
            // Merge the default settings with the provided settings
            $value += $defaultRedirect;
        }

        $request->getConfig()['redirect'] = $value;
        $request->getEmitter()->attach($this->redirectPlugin);
    }

    private function add_exceptions(RequestInterface $request, $value)
    {
        if ($value === true) {
            $request->getEmitter()->attach($this->errorPlugin);
        }
    }

    private function add_auth(RequestInterface $request, $value)
    {
        if (!$value) {
            return;
        } elseif (is_array($value)) {
            $authType = isset($value[2]) ? strtolower($value[2]) : 'basic';
        } else {
            $authType = strtolower($value);
        }

        $request->getConfig()->set('auth', $value);

        if ($authType == 'basic') {
            $request->setHeader(
                'Authorization',
                'Basic ' . base64_encode("$value[0]:$value[1]")
            );
        } elseif ($authType == 'digest') {
            // Currently only implemented by the cURL adapter.
            // @todo: Need an event listener solution that does not rely on cURL
            $config = $request->getConfig();
            $config->setPath('curl/' . CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            $config->setPath('curl/' . CURLOPT_USERPWD, "$value[0]:$value[1]");
        }
    }

    private function add_query(RequestInterface $request, $value)
    {
        if ($value instanceof Query) {
            $original = $request->getQuery();
            // Do not overwrite existing query string variables by overwriting
            // the object with the query string data passed in the URL
            $request->setQuery($value->overwriteWith($original->toArray()));
        } elseif (is_array($value)) {
            // Do not overwrite existing query string variables
            $query = $request->getQuery();
            foreach ($value as $k => $v) {
                if (!isset($query[$k])) {
                    $query[$k] = $v;
                }
            }
        } else {
            throw new \InvalidArgumentException('query value must be an array '
                . 'or Query object');
        }
    }

    private function add_headers(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('header value must be an array');
        }

        // Do not overwrite existing headers
        foreach ($value as $k => $v) {
            if (!$request->hasHeader($k)) {
                $request->setHeader($k, $v);
            }
        }
    }

    private function add_cookies(RequestInterface $request, $value)
    {
        if ($value === true) {
            static $cookie = null;
            if (!$cookie) {
                $cookie = new Cookie();
            }
            $request->getEmitter()->attach($cookie);
        } elseif (is_array($value)) {
            $request->getEmitter()->attach(
                new Cookie(CookieJar::fromArray($value, $request->getHost()))
            );
        } elseif ($value instanceof CookieJarInterface) {
            $request->getEmitter()->attach(new Cookie($value));
        } elseif ($value !== false) {
            throw new \InvalidArgumentException('cookies must be an array, '
                . 'true, or a CookieJarInterface object');
        }
    }

    private function add_events(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('events value must be an array');
        }

        $emitter = $request->getEmitter();
        foreach ($value as $name => $method) {
            if (is_callable($method)) {
                $emitter->on($name, $method);
            } elseif (!is_array($method) || !isset($method['fn'])) {
                throw new \InvalidArgumentException('Each event must be a '
                    . 'callable or associative array containing a "fn" key');
            } elseif (isset($method['once']) && $method['once'] === true) {
                $emitter->once(
                    $name,
                    $method['fn'],
                    isset($method['priority']) ? $method['priority'] : 0
                );
            } else {
                $emitter->on(
                    $name,
                    $method['fn'],
                    isset($method['priority']) ? $method['priority'] : 0
                );
            }
        }
    }

    private function add_subscribers(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('subscribers must be an array');
        }

        $emitter = $request->getEmitter();
        foreach ($value as $subscribers) {
            $emitter->attach($subscribers);
        }
    }
}
