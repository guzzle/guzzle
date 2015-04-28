<?php
namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use \InvalidArgumentException as Iae;

/**
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
    /** @var callable */
    private $handler;

    /** @var array Default request options */
    private $defaults;

    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using an URI template for the
     * client's base_uri and an array of default request options to apply
     * to each request:
     *
     *     $client = new Client([
     *         'base_uri'        => 'http://www.foo.com/1.0/',
     *         'timeout'         => 0,
     *         'allow_redirects' => false,
     *         'proxy'           => '192.168.16.1:10'
     *     ]);
     *
     * Client configuration settings include the following options:
     *
     * - handler: (callable) Function that transfers HTTP requests over the
     *   wire. The function is called with a Psr7\Http\Message\RequestInterface
     *   and array of transfer options, and must return a
     *   GuzzleHttp\Promise\PromiseInterface that is fulfilled with a
     *   Psr7\Http\Message\ResponseInterface on success. "handler" is a
     *   constructor only option that cannot be overridden in per/request
     *   options. If no handler is provided, a default handler will be created
     *   that enables all of the request options below by attaching all of the
     *   default middleware to the handler.
     * - base_uri: (string|UriInterface) Base URI of the client that is merged
     *   into relative URIs. Can be a string or instance of UriInterface.
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
     * - sink: (resource|string|StreamInterface) Where the data of the
     *   response is written to. Defaults to a PHP temp stream. Providing a
     *   string will write data to a file by the given name.
     * - stream: Set to true to attempt to stream a response rather than
     *   download it all up-front.
     * - expect: (bool|integer) Controls the behavior of the
     *   "Expect: 100-Continue" header.
     * - allow_redirects: (bool|array) Controls redirect behavior. Pass false
     *   to disable redirects, pass true to enable redirects, pass an
     *   associative to provide custom redirect settings. Defaults to "false".
     *   This option only works if your handler has the RedirectMiddleware.
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
     * - cookies: (bool|GuzzleHttp\Cookie\CookieJarInterface, default=false)
     *   Specifies whether or not cookies are used in a request or what cookie
     *   jar to use or what cookies to send. This option only works if your
     *   handler has the `cookie` middleware.
     * - http_errors: (bool, default=true) Set to false to disable exceptions
     *   when a non- successful HTTP response is received. By default,
     *   exceptions will be thrown for 4xx and 5xx responses. This option only
     *   works if your handler has the `httpErrors` middleware.
     * - json: (mixed) Adds JSON data to a request. The provided value is JSON
     *   encoded and a Content-Type header of application/json will be added to
     *   the request if no Content-Type header is already present.
     * - form_fields: (array) Associative array of field names to values where
     *   each value is a string or array of strings.
     * - form_files: (array) Array of associative arrays, each containing a
     *   required "name" key mapping to the form field, name, a required
     *   "contents" key mapping to a StreamInterface/resource/string, an
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
            $this->handler = HandlerStack::create();
        }

        // Convert the base_uri to a UriInterface
        if (isset($config['base_uri'])) {
            $config['base_uri'] = Psr7\uri_for($config['base_uri']);
        }

        $this->configureDefaults($config);
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
        $options = $this->prepareDefaults($options);

        return $this->transfer(
            $request->withUri($this->buildUri($request->getUri(), $options)),
            $options
        );
    }

    public function send(RequestInterface $request, array $options = [])
    {
        $options['sync'] = true;
        return $this->sendAsync($request, $options)->wait();
    }

    public function requestAsync($method, $uri = null, array $options = [])
    {
        $options = $this->prepareDefaults($options);
        // Remove request modifying parameter because it can be done up-front.
        $headers = isset($options['headers']) ? $options['headers'] : [];
        $body = isset($options['body']) ? $options['body'] : null;
        $version = isset($options['version']) ? $options['version'] : '1.1';
        // Merge the URI into the base URI.
        $uri = $this->buildUri($uri, $options);
        $request = new Psr7\Request($method, $uri, $headers, $body, $version);
        // Remove the option so that they are not doubly-applied.
        unset($options['headers'], $options['body'], $options['version']);

        return $this->transfer($request, $options);
    }

    public function request($method, $uri = null, array $options = [])
    {
        $options['sync'] = true;
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

    private function buildUri($uri, array $config)
    {
        if (!isset($config['base_uri'])) {
            return $uri instanceof UriInterface ? $uri : new Psr7\Uri($uri);
        }

        return Psr7\Uri::resolve(Psr7\uri_for($config['base_uri']), $uri);
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
            'allow_redirects' => RedirectMiddleware::$defaultSettings,
            'http_errors'     => true,
            'decode_content'  => true,
            'verify'          => true,
            'cookies'         => false
        ];

        // Use the standard Linux HTTP_PROXY and HTTPS_PROXY if set
        if ($proxy = getenv('HTTP_PROXY')) {
            $defaults['proxy']['http'] = $proxy;
        }

        if ($proxy = getenv('HTTPS_PROXY')) {
            $defaults['proxy']['https'] = $proxy;
        }

        $this->defaults = $config + $defaults;

        if (!empty($config['cookies']) && $config['cookies'] === true) {
            $this->defaults['cookies'] = new CookieJar();
        }

        // Add the default user-agent header.
        if (!isset($this->defaults['headers'])) {
            $this->defaults['headers'] = ['User-Agent' => default_user_agent()];
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
    private function prepareDefaults($options)
    {
        $defaults = $this->defaults;

        if (!empty($defaults['headers'])) {
            // Default headers are only added if they are not present.
            $defaults['_conditional'] = $defaults['headers'];
            unset($defaults['headers']);
        }

        // Special handling for headers is required as they are added as
        // conditional headers and as headers passed to a request ctor.
        if (array_key_exists('headers', $options)) {
            // Allows default headers to be unset.
            if ($options['headers'] === null) {
                $defaults['_conditional'] = null;
                unset($options['headers']);
            } elseif (!is_array($options['headers'])) {
                throw new \InvalidArgumentException('headers must be an array');
            }
        }

        // Shallow merge defaults underneath options.
        $result = $options + $defaults;

        // Remove null values.
        foreach ($result as $k => $v) {
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

        $request = $this->applyOptions($request, $options);
        $handler = $this->handler;

        try {
            return Promise\promise_for($handler($request, $options));
        } catch (\Exception $e) {
            return Promise\rejection_for($e);
        }
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

        // Extract POST/form parameters if present.
        if (!empty($options['form_files'])
            || !empty($options['form_fields'])
        ) {
            $this->extractFormData($options);
        }

        if (!empty($options['decode_content'])
            && $options['decode_content'] !== true
        ) {
            $modify['set_headers']['Accept-Encoding'] = $options['decode_content'];
        }

        if (isset($options['headers'])) {
            if (isset($modify['set_headers'])) {
                $modify['set_headers'] = $options['headers'] + $modify['set_headers'];
            } else {
                $modify['set_headers'] = $options['headers'];
            }
            unset($options['headers']);
        }

        if (isset($options['body'])) {
            $modify['body'] = Psr7\stream_for($options['body']);
            unset($options['body']);
        }

        if (!empty($options['auth'])) {
            $value = $options['auth'];
            $type = is_array($value)
                ? (isset($value[2]) ? strtolower($value[2]) : 'basic')
                : $value;
            $config['auth'] = $value;
            switch (strtolower($type)) {
                case 'basic':
                    $modify['set_headers']['Authorization'] = 'Basic '
                        . base64_encode("$value[0]:$value[1]");
                    break;
                case 'digest':
                    // @todo: Do not rely on curl
                    $options['curl'][CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
                    $options['curl'][CURLOPT_USERPWD] = "$value[0]:$value[1]";
                    break;
            }
        }

        if (isset($options['query'])) {
            $value = $options['query'];
            if (is_array($value)) {
                $value = http_build_query($value, null, null, PHP_QUERY_RFC3986);
            }
            if (!is_string($value)) {
                throw new Iae('query must be a string or array');
            }
            $modify['query'] = $value;
            unset($options['query']);
        }

        if (isset($options['json'])) {
            $modify['body'] = Psr7\stream_for(json_encode($options['json']));
            $options['_conditional']['Content-Type'] = 'application/json';
            unset($options['json']);
        }

        $request = Psr7\modify_request($request, $modify);

        // Merge in conditional headers if they are not present.
        if (isset($options['_conditional'])) {
            // Build up the changes so it's in a single clone of the message.
            $modify = [];
            foreach ($options['_conditional'] as $k => $v) {
                if (!$request->hasHeader($k)) {
                    $modify['set_headers'][$k] = $v;
                }
            }
            $request = Psr7\modify_request($request, $modify);
            // Don't pass this internal value along to middleware/handlers.
            unset($options['_conditional']);
        }

        return $request;
    }

    private function extractFormData(array &$options)
    {
        $fields = [];
        if (isset($options['form_fields'])) {
            // Use a application/x-www-form-urlencoded POST with no files.
            if (!isset($options['form_files'])) {
                $options['body'] = http_build_query($options['form_fields']);
                unset($options['form_fields']);
                $options['_conditional']['Content-Type'] = 'application/x-www-form-urlencoded';
                return;
            }
            $fields = $options['form_fields'];
            unset($options['form_fields']);
        }

        $files = $options['form_files'];
        unset($options['form_files']);
        $options['body'] = new MultipartPostBody($fields, $files);
        // Use a multipart/form-data POST if a Content-Type is not set.
        $options['_conditional']['Content-Type'] = 'multipart/form-data; boundary='
            . $options['body']->getBoundary();
    }
}
