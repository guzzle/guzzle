<?php

namespace Guzzle\Http\Message;

use Guzzle\Http\Message\Post\PostFileInterface;
use Guzzle\Http\Subscriber\Cookie;
use Guzzle\Http\CookieJar\ArrayCookieJar;
use Guzzle\Http\CookieJar\CookieJarInterface;
use Guzzle\Http\Subscriber\HttpError;
use Guzzle\Http\Message\Post\PostBody;
use Guzzle\Http\Message\Post\PostFile;
use Guzzle\Http\Subscriber\Redirect;
use Guzzle\Stream\Stream;
use Guzzle\Url\QueryString;
use Guzzle\Url\Url;

/**
 * Default HTTP request factory used to create {@see Request} and {@see Response} objects
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

    public function createResponse($statusCode , array $headers = [], $body = null, array $options = [])
    {
        if (null !== $body) {
            $body = Stream::factory($body);
        }

        return new Response($statusCode, $headers, $body, $options);
    }

    public function createRequest($method, $url, array $options = [])
    {
        $request = new Request($method, $url, [], null,
            isset($options['options']) ? $options['options'] : []);

        unset($options['options']);

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
            throw new \InvalidArgumentException('Unable to parse request message');
        }

        return $this->createRequest(
            $data['method'],
            Url::buildUrl($data['request_url']),
            [
                'headers' => $data['headers'],
                'body' => $data['body'] === '' ? null : $data['body'],
                'options' => [
                    'protocol_version' => $data['protocol_version']
                ]
            ]
        );
    }

    /**
     * Apply POST fields and files to a request to attempt to give an accurate representation
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

    protected function applyOptions(RequestInterface $request, array $options = array())
    {
        // Values specified in the config map are passed to request options
        static $configMap = ['connect_timeout' => 1, 'timeout' => 1, 'verify' => 1,
            'ssl_key' => 1, 'cert' => 1, 'proxy' => 1, 'debug' => 1,
            'save_to' => 1, 'stream' => 1, 'expect' => 1];
        static $methods;
        if (!$methods) {
            $methods = array_flip(get_class_methods(__CLASS__));
        }

        // Iterate over each key value pair and attempt to apply a config using function visitor
        $config = $request->getConfig();
        foreach ($options as $key => $value) {
            $method = "visit_{$key}";
            if (isset($methods[$method])) {
                $this->{$method}($request, $value);
            } elseif (isset($configMap[$key])) {
                $config[$key] = $value;
            } else {
                throw new \InvalidArgumentException("No method is configured to handle the {$key} config key");
            }
        }
    }

    private function visit_body(RequestInterface $request, $value)
    {
        if ($value !== null) {
            if (is_array($value)) {
                $this->addPostData($request, $value);
            } else {
                $request->setBody(Stream::factory($value));
            }
        }
    }

    private function visit_config(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('config value must be an associative array');
        }

        $request->getConfig()->overwriteWith($value);
    }

    private function visit_allow_redirects(RequestInterface $request, $value)
    {
        if ($value === false) {
            return;
        }

        if (isset($value['max'])) {
            $request->getConfig()['max_redirects'] = $value['max'];
            if (isset($value['strict']) and $value['strict']) {
                $request->getConfig()['strict_redirects'] = true;
            }
        } elseif ($value !== true) {
            throw new \InvalidArgumentException('allow_redirects must be'
                . 'true, false, or an array that contains the \'max\' key');
        }

        $request->getEmitter()->addSubscriber($this->redirectPlugin);
    }

    private function visit_exceptions(RequestInterface $request, $value)
    {
        if ($value === true) {
            $request->getEmitter()->addSubscriber($this->errorPlugin);
        }
    }

    private function visit_auth(RequestInterface $request, $value)
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
            // We can easily handle simple basic Auth in the factory
            $request->setHeader('Authorization', 'Basic ' . base64_encode("$value[0]:$value[1]"));
        } elseif ($authType == 'digest') {
            // Currently only implemented by the cURL adapter.
            // @todo: Need an event listener solution that does not rely on cURL
            $request->getConfig()->setPath('curl/' . CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            $request->getConfig()->setPath('curl/' . CURLOPT_USERPWD, "$value[0]:$value[1]");
        }
    }

    private function visit_query(RequestInterface $request, $value)
    {
        if ($value instanceof QueryString) {
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
            throw new \InvalidArgumentException('query value must be an array');
        }
    }

    private function visit_headers(RequestInterface $request, $value)
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

    private function visit_cookies(RequestInterface $request, $value)
    {
        if ($value === true) {
            static $cookie = null;
            $request->getEmitter()->addSubscriber($cookie = $cookie ?: new Cookie());
        } elseif (is_array($value)) {
            $request->getEmitter()->addSubscriber(
                new Cookie(ArrayCookieJar::fromArray($value, $request->getHost()))
            );
        } elseif ($value instanceof CookieJarInterface) {
            $request->getEmitter()->addSubscriber(new Cookie($value));
        } elseif ($value !== false) {
            throw new \InvalidArgumentException('cookies must be an array, true, or a CookieJarInterface object');
        }
    }

    private function visit_events(RequestInterface $request, $value)
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

    private function visit_subscribers(RequestInterface $request, $value)
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('subscribers value must be an array');
        }

        $emitter = $request->getEmitter();
        foreach ($value as $subscribers) {
            $emitter->addSubscriber($subscribers);
        }
    }
}
