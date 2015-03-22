<?php
namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use \InvalidArgumentException as Iae;

/**
 * GuzzleHttp client implementation.
 *
 *     $client = new GuzzleHttp\Client();
 *     $response = $client->request('GET', 'http://www.google.com');
 *     $response->then(
 *         function ($response) {
 *             echo "Got a response: \n" . GuzzleHttp\str_message($response) . "\n";
 *         },
 *         function ($e) {
 *             echo 'Got an error: ' . $e->getMessage() . "\n";
 *         }
 *     );
 *     $response->wait();
 *     echo $response->getStatusCode();
 *
 * @method ResponseInterface get($uri, array $options = [])
 * @method ResponseInterface head($uri, array $options = [])
 * @method ResponseInterface put($uri, array $options = [])
 * @method ResponseInterface post($uri, array $options = [])
 * @method ResponseInterface patch($uri, array $options = [])
 * @method ResponseInterface delete($uri, array $options = [])
 * @method PromiseInterface getAsync($uri, array $options = [])
 * @method PromiseInterface headAsync($uri, array $options = [])
 * @method PromiseInterface putAsync($uri, array $options = [])
 * @method PromiseInterface postAsync($uri, array $options = [])
 * @method PromiseInterface patchAsync($uri, array $options = [])
 * @method PromiseInterface deleteAsync($uri, array $options = [])
 */
class Client implements ClientInterface
{
    /** @var Psr7\Uri Base URI of the client */
    private $baseUri;

    /** @var array Default request options */
    private $defaults;

    /** @var callable Request handler */
    private $handler;

    /** @var callable Cached error middleware */
    private $errorMiddleware;

    /** @var callable Cached redirect middleware */
    private $redirectMiddleware;

    /** @var callable Cached cookie middleware */
    private $cookieMiddleware;

    /** @var callable Cached prepare body middleware */
    private $prepareBodyMiddleware;

    /** @var array Known pass-through transfer request options */
    private static $transferOptions = [
        'connect_timeout' => true,
        'timeout' => true,
        'verify' => true,
        'ssl_key' => true,
        'cert' => true,
        'progress' => true,
        'proxy' => true,
        'debug' => true,
        'sink' => true,
        'stream' => true,
        'expect' => true,
        'allow_redirects' => true,
        'sync' => true
    ];

    /** @var array Default allow_redirects request option settings  */
    private static $defaultRedirect = [
        'max'       => 5,
        'strict'    => false,
        'referer'   => false,
        'protocols' => ['http', 'https']
    ];

    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using an URI template for the
     * client's base_uri and an array of default request options to apply
     * to each request:
     *
     *     $client = new Client([
     *         'base_uri' => [
     *              'http://www.foo.com/{version}/',
     *              ['version' => '123']
     *          ],
     *         'defaults' => [
     *             'timeout'         => 0,
     *             'allow_redirects' => false,
     *             'proxy'           => '192.168.16.1:10'
     *         ]
     *     ]);
     *
     * @param array $config Client configuration settings
     *     - base_uri: Base URI of the client that is merged into relative URIs.
     *       Can be a string or an array that contains a URI template followed
     *       by an associative array of expansion variables to inject into the
     *       URI template.
     *     - handler: callable RingPHP handler used to transfer requests
     *     - defaults: Default request options to apply to each request
     */
    public function __construct(array $config = [])
    {
        $this->configureBaseUri($config);
        $this->configureDefaults($config);
        $this->prepareBodyMiddleware = Middleware::prepareBody();
        $this->handler = isset($config['handler'])
            ? $config['handler']
            : default_handler();
    }

    public function __call($method, $args)
    {
        if (count($args) < 1) {
            throw new \InvalidArgumentException('Magic request methods require a URI and optional options array');
        }

        $uri = $args[0];
        $opts = isset($args[1]) ? $args[1] : [];

        return substr($method, -5) === 'Async'
            ? $this->requestAsync(substr($method, 0, -5), $uri, $opts)
            : $this->request($method, $uri, $opts);
    }

    public function sendAsync(RequestInterface $request, array $options = [])
    {
        // Merge the base URI into the request URI if needed.
        $original = $request->getUri();
        $uri = $this->buildUri($original);
        if ($uri !== $original) {
            $request = $request->withUri($uri);
        }

        return $this->transfer($request, $this->mergeDefaults($options));
    }

    public function send(RequestInterface $request, array $options = [])
    {
        return $this->sendAsync($request, $options)->wait();
    }

    public function requestAsync($method, $uri = null, array $options = [])
    {
        $options = $this->mergeDefaults($options);
        $headers = isset($options['headers']) ? $options['headers'] : [];
        $body = isset($options['body']) ? $options['body'] : null;
        $version = isset($options['version']) ? $options['version'] : '1.1';
        // Merge the URI into the base URI.
        $uri = $this->buildUri($uri);
        $request = new Psr7\Request($method, $uri, $headers, $body, $version);
        unset($options['headers'], $options['body'], $options['version']);

        return $this->transfer($request, $options);
    }

    public function request($method, $uri = null, array $options = [])
    {
        return $this->requestAsync($method, $uri, $options)->wait();
    }

    public function getDefaultOption($keyOrPath = null)
    {
        return $keyOrPath === null
            ? $this->defaults
            : get_path($this->defaults, $keyOrPath);
    }

    public function setDefaultOption($keyOrPath, $value)
    {
        set_path($this->defaults, $keyOrPath, $value);
    }

    public function getBaseUri()
    {
        return $this->baseUri;
    }

    /**
     * Expand a URI template and inherit from the base URL if it's relative
     *
     * @param string|array $uri URL or an array of the URI template to expand
     *                          followed by a hash of template varnames.
     * @return UriInterface
     * @throws \InvalidArgumentException
     */
    private function buildUri($uri)
    {
        // URI template (absolute or relative)
        if (!is_array($uri)) {
            return Psr7\Uri::resolve($this->baseUri, $uri);
        }

        if (!isset($uri[1])) {
            throw new \InvalidArgumentException('You must provide a hash of '
                . 'varname options in the second element of a URL array.');
        }

        // Absolute URL
        if (strpos($uri[0], '://')) {
            return new Psr7\Uri(uri_template($uri[0], $uri[1]));
        }

        // Combine the relative URL with the base URL
        return Psr7\Uri::resolve(
            $this->baseUri,
            uri_template($uri[0], $uri[1])
        );
    }

    private function configureBaseUri($config)
    {
        if (!isset($config['base_uri'])) {
            $this->baseUri = new Psr7\Uri('');
        } elseif (!is_array($config['base_uri'])) {
            $this->baseUri = new Psr7\Uri($config['base_uri']);
        } elseif (count($config['base_uri']) < 2) {
            throw new \InvalidArgumentException('You must provide a hash of '
                . 'varname options in the second element of a base_uri array.');
        } else {
            $this->baseUri = new Psr7\Uri(
                uri_template($config['base_uri'][0], $config['base_uri'][1])
            );
        }
    }

    /**
     * Configures the default options for a client.
     *
     * @param array $config
     *
     * @return array
     */
    private function configureDefaults(array $config)
    {
        $defaults = [
            'allow_redirects' => self::$defaultRedirect,
            'exceptions'      => true,
            'decode_content'  => true,
            'verify'          => true
        ];

        // Use the standard Linux HTTP_PROXY and HTTPS_PROXY if set
        if ($proxy = getenv('HTTP_PROXY')) {
            $defaults['proxy']['http'] = $proxy;
        }

        if ($proxy = getenv('HTTPS_PROXY')) {
            $defaults['proxy']['https'] = $proxy;
        }

        $this->defaults = empty($config['defaults'])
            ? $defaults
            : $config['defaults'] + $defaults;

        // Add the default user-agent header.
        if (!isset($this->defaults['headers'])) {
            $this->defaults['headers'] = [
                'User-Agent' => default_user_agent()
            ];
        } else {
            // Add the User-Agent header if one was not already set.
            foreach (array_keys($this->defaults['headers']) as $name) {
                if (strtolower($name) === 'user-agent') {
                    return;
                }
            }
            $this->defaults['headers']['User-Agent'] = default_user_agent();
        }
    }

    /**
     * Merges default options into the array.
     *
     * @param array $options Options to modify by reference
     *
     * @return array
     */
    private function mergeDefaults($options)
    {
        $defaults = $this->defaults;

        // Case-insensitively merge in default headers if both defaults and
        // options have headers specified.
        if (!empty($defaults['headers']) && !empty($options['headers'])) {
            // Create a set of lowercase keys that are present.
            $lkeys = [];
            foreach (array_keys($options['headers']) as $k) {
                $lkeys[strtolower($k)] = true;
            }
            // Merge in lowercase default keys when not present in above set.
            foreach ($defaults['headers'] as $key => $value) {
                if (!isset($lkeys[strtolower($key)])) {
                    $options['headers'][$key] = $value;
                }
            }
            // No longer need to merge in headers.
            unset($defaults['headers']);
        }

        $result = array_replace_recursive($defaults, $options);
        foreach ($options as $k => $v) {
            if ($v === null) {
                unset($result[$k]);
            }
        }

        return $result;
    }

    /**
     * Transfers the given request and applies request options.
     *
     * The URI of the request is not modified and the request options are used
     * as-is without merging in default options.
     *
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return PromiseInterface
     */
    private function transfer(RequestInterface $request, array $options)
    {
        if (!isset($options['stack'])) {
            $options['stack'] = new HandlerBuilder();
        } elseif (!($options['stack'] instanceof HandlerBuilder)) {
            throw new \InvalidArgumentException('The stack option must be an instance of GuzzleHttp\\HandlerBuilder');
        }

        $handler = $this->createHandler($request, $options);
        $request = $this->applyOptions($request, $options);

        try {
            $response = $handler($request, $options);
            if ($response instanceof PromiseInterface) {
                return $response;
            }
            return \GuzzleHttp\Promise\promise_for($response);
        } catch (\Exception $e) {
            return \GuzzleHttp\Promise\rejection_for($e);
        }
    }

    /**
     * Create a composite handler based on the given request options.
     *
     * @param RequestInterface $request Request to send.
     * @param array            $options Array of request options.
     *
     * @return callable
     */
    private function createHandler(RequestInterface $request, array &$options)
    {
        /** @var HandlerBuilder $stack */
        $stack = $options['stack'];

        // Add the redirect middleware if needed.
        if (!empty($options['allow_redirects'])) {
            if (!$this->errorMiddleware) {
                $this->redirectMiddleware = Middleware::redirect();
            }
            $stack->append($this->redirectMiddleware);
            if ($options['allow_redirects'] === true) {
                $options['allow_redirects'] = self::$defaultRedirect;
            } elseif (!is_array($options['allow_redirects'])) {
                throw new Iae('allow_redirects must be true, false, or array');
            } else {
                // Merge the default settings with the provided settings
                $options['allow_redirects'] += self::$defaultRedirect;
            }
        }

        // Add the httpError middleware if needed.
        if (!empty($options['exceptions'])) {
            if (!$this->errorMiddleware) {
                $this->errorMiddleware = Middleware::httpError();
            }
            $stack->append($this->errorMiddleware);
            unset($options['exceptions']);
        }

        // Add the cookies middleware if needed.
        if (!empty($options['cookies'])) {
            if ($options['cookies'] === true) {
                if (!$this->cookieMiddleware) {
                    $jar = new CookieJar();
                    $this->cookieMiddleware = Middleware::cookies($jar);
                }
                $cookie = $this->cookieMiddleware;
            } elseif ($options['cookies'] instanceof CookieJarInterface) {
                $cookie = Middleware::cookies($options['cookies']);
            } elseif (is_array($options['cookies'])) {
                $cookie = Middleware::cookies(CookieJar::fromArray(
                    $options['cookies'],
                    $request->getUri()->getHost()
                ));
            } else {
                throw new Iae('cookies must be an array, true, or CookieJarInterface');
            }
            $stack->append($cookie);
        }

        $stack->append($this->prepareBodyMiddleware);

        if (!$stack->hasHandler()) {
            $stack->setHandler($this->handler);
        }

        return $stack->resolve();
    }

    /**
     * Applies the array of request options to a request.
     *
     * @param RequestInterface $request
     * @param array            $options
     *
     * @return RequestInterface
     */
    private function applyOptions(RequestInterface $request, array &$options)
    {
        $modify = [];
        $this->extractPostData($options);

        foreach ($options as $key => $value) {
            if (isset(self::$transferOptions[$key])) {
                $config[$key] = $value;
                continue;
            }
            switch ($key) {

                case 'decode_content':
                    if ($value === false) {
                        continue;
                    }
                    if ($value !== true) {
                        $modify['set_headers']['Accept-Encoding'] = $value;
                    }
                    break;

                case 'headers':
                    if (!is_array($value)) {
                        throw new Iae('header value must be an array');
                    }
                    foreach ($value as $k => $v) {
                        $modify['set_headers'][$k] = $v;
                    }
                    unset($options['headers']);
                    break;

                case 'body':
                    $modify['body'] = Psr7\Stream::factory($value);
                    unset($options['body']);
                    break;

                case 'auth':
                    if (!$value) {
                        continue;
                    }
                    if (is_array($value)) {
                        $type = isset($value[2]) ? strtolower($value[2]) : 'basic';
                    } else {
                        $type = strtolower($value);
                    }
                    $config['auth'] = $value;
                    if ($type == 'basic') {
                        $modify['set_headers']['Authorization'] = 'Basic ' . base64_encode("$value[0]:$value[1]");
                    } elseif ($type == 'digest') {
                        // @todo: Do not rely on curl
                        $options['curl'][CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
                        $options['curl'][CURLOPT_USERPWD] = "$value[0]:$value[1]";
                    }
                    break;

                case 'query':
                    if (is_array($value)) {
                        $value = http_build_query($value, null, null, PHP_QUERY_RFC3986);
                    }
                    if (!is_string($value)) {
                        throw new Iae('query must be a string or array');
                    }
                    $modify['query'] = $value;
                    unset($options['query']);
                    break;

                case 'json':
                    $modify['body'] = Psr7\Stream::factory(json_encode($value));
                    if (!$request->hasHeader('Content-Type')) {
                        $modify['set_headers']['Content-Type'] = 'application/json';
                    }
                    unset($options['json']);
                    break;
            }
        }

        return Psr7\modify_request($request, $modify);
    }

    /**
     * Extracts post_fields and post_files into the "body" option.
     *
     * @param array $options
     */
    private function extractPostData(array &$options)
    {
        if (empty($options['post_files']) && empty($options['post_fields'])) {
            return;
        }

        $contentType = null;
        if (!empty($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                if (strtolower($name) === 'content-type') {
                    $contentType = $value;
                    break;
                }
            }
        }

        $fields = [];
        if (isset($options['post_fields'])) {
            if (!isset($options['post_files'])) {
                $options['body'] = http_build_query($options['post_fields']);
                unset($options['post_fields']);
                $options['headers']['Content-Type'] = $contentType ?: 'application/x-www-form-urlencoded';
                return;
            }
            $fields = $options['post_fields'];
            unset($options['post_fields']);
        }

        $files = $options['post_files'];
        unset($options['post_files']);
        $options['body'] = new MultipartPostBody($fields, $files);
        $options['headers']['Content-Type'] = $contentType
            ?: 'multipart/form-data; boundary=' . $options['body']->getBoundary();
    }
}
