<?php
namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Promise;
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
 * @method Promise\PromiseInterface getAsync($uri, array $options = [])
 * @method Promise\PromiseInterface headAsync($uri, array $options = [])
 * @method Promise\PromiseInterface putAsync($uri, array $options = [])
 * @method Promise\PromiseInterface postAsync($uri, array $options = [])
 * @method Promise\PromiseInterface patchAsync($uri, array $options = [])
 * @method Promise\PromiseInterface deleteAsync($uri, array $options = [])
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
        'delay' => true,
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
     *          'timeout'         => 0,
     *          'allow_redirects' => false,
     *          'proxy'           => '192.168.16.1:10'
     *     ]);
     *
     * Client configuration settings include the following options:
     *
     * - base_uri: (string) Base URI of the client that is merged into relative
     *   URIs. Can be a string or an array that contains a URI template
     *   followed by an associative array of expansion variables to inject into
     *   the URI template.
     * - handler: (callable) Function that transfers HTTP requests over the
     *   wire. The function is called with a Psr7\Http\Message\RequestInterface
     *   and array of transfer options, and must return a
     *   GuzzleHttp\Promise\PromiseInterface that is fulfilled with a
     *   Psr7\Http\Message\ResponseInterface on success.
     * - delay: (int) The amount of time to delay before sending in
     *   milliseconds.
     * - connect_timeout: (float, default=0) Float describing the number of
     *   seconds to wait while trying to connect to a server. Use 0 to wait
     *   indefinitely (the default behavior).
     * - timeout: (float, default=0) Float describing the timeout of the
     *   request in seconds. Use 0 to wait indefinitely (the default behavior).
     * - verify: (bool|string, default=true) Describes the SSL certificate
     *   verification behavior of a request. Set to true to enable SSL
     *   certificate verification using the system CA bundle when available
     *   (the default). Set to false to disable certificate verification (this
     *   is insecure!). Set to a string to provide the path to a CA bundle on
     *   disk to enable verification using a custom certificate.
     * - ssl_key: (array) Specify the path to a file containing a private SSL
     *   key in PEM format. If a password is required, then set to an array
     *   containing the path to the SSL key in the first array element followed
     *   by the password required for the certificate in the second element.
     * - cert: (array) Set to a string to specify the path to a file containing
     *   a PEM formatted SSL client side certificate. If a password is
     *   required, then set cert to an array containing the path to the PEM
     *   file in the first array element followed by the certificate password
     *   in the second array element.
     * - progress: (callable) Defines a function to invoke when transfer
     *   progress is made. The function accepts the following positional
     *   arguments: the total number of bytes expected to be downloaded, the
     *   number of bytes downloaded so far, the number of bytes expected to be
     *   uploaded, the number of bytes uploaded so far.
     * - proxy: (string|array)
     * - debug: (bool|resource) Set to true or set to a PHP stream returned by
     *   fopen()  enable debug output with the HTTP handler used to send a
     *   request.
     * - sink: (resource|string|StreamableInterface) Where the data of the
     *   response is written to. Defaults to a PHP temp stream. Providing a
     *   string will write data to a file by the given name.
     * - stream: Set to true to attempt to stream a response rather than
     *   download it all up-front.
     * - expect: (bool|integer) Controls the behavior of the
     *   "Expect: 100-Continue" header.
     * - allow_redirects: (bool|array) Controls redirect behavior. Pass false
     *   to disable redirects, pass true to enable redirects, pass an
     *   associative to provide custom redirect settings. Defaults to "false".
     * - sync: (bool) Set to true to inform HTTP handlers that you intend on
     *   waiting on the response. This can be useful for optimizations.
     * - decode_content: (bool, default=true) Specify whether or not
     *   Content-Encoding responses (gzip, deflate, etc.) are automatically
     *   decoded.
     * - headers: (array) Associative array of HTTP headers. Each value MUST be
     *   a string or array of strings.
     * - body: (string|null|callable|iterator|object) Body to send in the
     *   request.
     * - query: (array|string) Associative array of query string values to add
     *   to the request. This option uses PHP's http_build_query() to create
     *   the string representation. Pass a string value if you need more
     *   control than what this method provides
     * - auth: (array) Pass an array of HTTP authentication parameters to use
     *   with the request. The array must contain the username in index [0],
     *   the password in index [1], and you can optionally provide a built-in
     *   authentication type in index [2]. Pass null to disable authentication
     *   for a request.
     * - cookies: (bool|array|GuzzleHttp\Cookie\CookieJarInterface, default=false)
     *   Specifies whether or not cookies are used in a request or what cookie
     *   jar to use or what cookies to send.
     * - http_errors: (bool, default=true) Set to false to disable exceptions
     *   when a non- successful HTTP response is received. By default,
     *   exceptions will be thrown for 4xx and 5xx responses.
     * - json: (mixed) Adds JSON data to a request. The provided value is JSON
     *   encoded and a Content-Type header of application/json will be added to
     *   the request if no Content-Type header is already present.
     * - form_fields: (array) Associative array of field names to values where
     *   each value is a string or array of strings.
     * - form_files: (array) Array of associative arrays, each containing a
     *   required "name" key mapping to the form field, name, a required
     *   "contents" key mapping to a StreamableInterface/resource/string, an
     *   optional "headers" associative array of custom headers, and an
     *   optional "filename" key mapping to a string to send as the filename in
     *   the part.
     *
     * @param array $config Client configuration settings.
     */
    public function __construct(array $config = [])
    {
        if (isset($config['handler'])) {
            $this->handler = $config['handler'];
            unset($config['handler']);
        } else {
            $this->handler = default_handler();
        }

        $this->configureBaseUri($config);
        unset($config['base_uri']);
        $this->configureDefaults($config);
        $this->prepareBodyMiddleware = Middleware::prepareBody();
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

    public function getDefaultOption($option = null)
    {
        return $option === null
            ? $this->defaults
            : (isset($this->defaults[$option])
                ? $this->defaults[$option]
                : null);
    }

    public function setDefaultOption($option, $value)
    {
        $this->defaults[$option] = $value;
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
            'http_errors'     => true,
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

        $this->defaults = $config + $defaults;

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
     * @return Promise\PromiseInterface
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
            return Promise\promise_for($handler($request, $options));
        } catch (\Exception $e) {
            return Promise\rejection_for($e);
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
        if (!empty($options['http_errors'])) {
            if (!$this->errorMiddleware) {
                $this->errorMiddleware = Middleware::httpError();
            }
            $stack->append($this->errorMiddleware);
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
        $this->extractFormData($options);
        $this->backwardsCompat($options);

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
     * Extracts form_fields and form_files into the "body" option.
     *
     * @param array $options
     */
    private function extractFormData(array &$options)
    {
        if (empty($options['form_files']) && empty($options['form_fields'])) {
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
        if (isset($options['form_fields'])) {
            // Use a application/x-www-form-urlencoded POST with no files.
            if (!isset($options['form_files'])) {
                $options['body'] = http_build_query($options['form_fields']);
                unset($options['form_fields']);
                $options['headers']['Content-Type'] = $contentType
                    ?: 'application/x-www-form-urlencoded';
                return;
            }
            $fields = $options['form_fields'];
            unset($options['form_fields']);
        }

        $files = $options['form_files'];
        unset($options['form_files']);
        $options['body'] = new MultipartPostBody($fields, $files);
        // Use a multipart/form-data POST if a Content-Type is not set.
        $options['headers']['Content-Type'] = $contentType
            ?: 'multipart/form-data; boundary=' . $options['body']->getBoundary();
    }

    private function backwardsCompat(array &$options)
    {
        // save_to -> sink
        if (isset($options['save_to'])) {
            $options['sink'] = $options['save_to'];
            unset($options['save_to']);
        }

        // exceptions -> http_error
        if (isset($options['exceptions'])) {
            $options['http_errors'] = $options['exceptions'];
            unset($options['exceptions']);
        }
    }
}
