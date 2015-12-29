<?php
namespace GuzzleHttp;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
    /** @var array Default request options */
    private $config;

    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using a base_uri and an array of
     * default request options to apply to each request:
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
     * - **: any request option
     *
     * @param array $config Client configuration settings.
     *
     * @see \GuzzleHttp\RequestOptions for a list of available request options.
     */
    public function __construct(array $config = [])
    {
        if (!isset($config['handler'])) {
            $config['handler'] = HandlerStack::create();
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
        $options[RequestOptions::SYNCHRONOUS] = true;
        return $this->sendAsync($request, $options)->wait();
    }

    public function requestAsync($method, $uri = null, array $options = [])
    {
        $options = $this->prepareDefaults($options);
        // Remove request modifying parameter because it can be done up-front.
        $headers = isset($options[RequestOptions::HEADERS]) ? $options[RequestOptions::HEADERS] : [];
        $body = isset($options[RequestOptions::BODY]) ? $options[RequestOptions::BODY] : null;
        $version = isset($options[RequestOptions::VERSION]) ? $options[RequestOptions::VERSION] : '1.1';
        // Merge the URI into the base URI.
        $uri = $this->buildUri($uri, $options);
        if (is_array($body)) {
            $this->invalidBody();
        }
        $request = new Psr7\Request($method, $uri, $headers, $body, $version);
        // Remove the option so that they are not doubly-applied.
        unset($options[RequestOptions::HEADERS], $options[RequestOptions::BODY], $options[RequestOptions::VERSION]);

        return $this->transfer($request, $options);
    }

    public function request($method, $uri = null, array $options = [])
    {
        $options[RequestOptions::SYNCHRONOUS] = true;
        return $this->requestAsync($method, $uri, $options)->wait();
    }

    public function getConfig($option = null)
    {
        return $option === null
            ? $this->config
            : (isset($this->config[$option]) ? $this->config[$option] : null);
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
     */
    private function configureDefaults(array $config)
    {
        $defaults = [
            RequestOptions::ALLOW_REDIRECTS => RedirectMiddleware::$defaultSettings,
            RequestOptions::HTTP_ERRORS     => true,
            RequestOptions::DECODE_CONTENT  => true,
            RequestOptions::VERIFY          => true,
            RequestOptions::COOKIES         => false
        ];

        // Use the standard Linux HTTP_PROXY and HTTPS_PROXY if set
        if ($proxy = getenv('HTTP_PROXY')) {
            $defaults[RequestOptions::PROXY]['http'] = $proxy;
        }

        if ($proxy = getenv('HTTPS_PROXY')) {
            $defaults[RequestOptions::PROXY]['https'] = $proxy;
        }

        if ($noProxy = getenv('NO_PROXY')) {
            $cleanedNoProxy = str_replace(' ', '', $noProxy);
            $defaults[RequestOptions::PROXY]['no'] = explode(',', $cleanedNoProxy);
        }
        
        $this->config = $config + $defaults;

        if (!empty($config[RequestOptions::COOKIES]) && $config[RequestOptions::COOKIES] === true) {
            $this->config[RequestOptions::COOKIES] = new CookieJar();
        }

        // Add the default user-agent header.
        if (!isset($this->config[RequestOptions::HEADERS])) {
            $this->config[RequestOptions::HEADERS] = ['User-Agent' => default_user_agent()];
        } else {
            // Add the User-Agent header if one was not already set.
            foreach (array_keys($this->config[RequestOptions::HEADERS]) as $name) {
                if (strtolower($name) === 'user-agent') {
                    return;
                }
            }
            $this->config[RequestOptions::HEADERS]['User-Agent'] = default_user_agent();
        }
    }

    /**
     * Merges default options into the array.
     *
     * @param array $options Options to modify by reference
     *
     * @throws \InvalidArgumentException
     * @return array
     */
    private function prepareDefaults($options)
    {
        $defaults = $this->config;

        if (!empty($defaults[RequestOptions::HEADERS])) {
            // Default headers are only added if they are not present.
            $defaults['_conditional'] = $defaults[RequestOptions::HEADERS];
            unset($defaults[RequestOptions::HEADERS]);
        }

        // Special handling for headers is required as they are added as
        // conditional headers and as headers passed to a request ctor.
        if (array_key_exists(RequestOptions::HEADERS, $options)) {
            // Allows default headers to be unset.
            if ($options[RequestOptions::HEADERS] === null) {
                $defaults['_conditional'] = null;
                unset($options[RequestOptions::HEADERS]);
            } elseif (!is_array($options[RequestOptions::HEADERS])) {
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
            $options[RequestOptions::SINK] = $options['save_to'];
            unset($options['save_to']);
        }

        // exceptions -> http_error
        if (isset($options['exceptions'])) {
            $options[RequestOptions::HTTP_ERRORS] = $options['exceptions'];
            unset($options['exceptions']);
        }

        $request = $this->applyOptions($request, $options);
        $handler = $options['handler'];

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
     * @param array $options
     *
     * @throws \InvalidArgumentException
     * @return RequestInterface
     */
    private function applyOptions(RequestInterface $request, array &$options)
    {
        $modify = [];

        if (isset($options[RequestOptions::FORM_PARAMS])) {
            if (isset($options[RequestOptions::MULTIPART])) {
                throw new \InvalidArgumentException('You cannot use '
                    . 'form_params and multipart at the same time. Use the '
                    . 'form_params option if you want to send application/'
                    . 'x-www-form-urlencoded requests, and the multipart '
                    . 'option to send multipart/form-data requests.');
            }
            $options[RequestOptions::BODY] = http_build_query($options[RequestOptions::FORM_PARAMS], null, '&');
            unset($options[RequestOptions::FORM_PARAMS]);
            $options['_conditional']['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        if (isset($options[RequestOptions::MULTIPART])) {
            $elements = $options[RequestOptions::MULTIPART];
            unset($options[RequestOptions::MULTIPART]);
            $options[RequestOptions::BODY] = new Psr7\MultipartStream($elements);
        }

        if (!empty($options[RequestOptions::DECODE_CONTENT])
            && $options[RequestOptions::DECODE_CONTENT] !== true
        ) {
            $modify['set_headers']['Accept-Encoding'] = $options[RequestOptions::DECODE_CONTENT];
        }

        if (isset($options[RequestOptions::HEADERS])) {
            if (isset($modify['set_headers'])) {
                $modify['set_headers'] = $options[RequestOptions::HEADERS] + $modify['set_headers'];
            } else {
                $modify['set_headers'] = $options[RequestOptions::HEADERS];
            }
            unset($options[RequestOptions::HEADERS]);
        }

        if (isset($options[RequestOptions::BODY])) {
            if (is_array($options[RequestOptions::BODY])) {
                $this->invalidBody();
            }
            $modify[RequestOptions::BODY] = Psr7\stream_for($options[RequestOptions::BODY]);
            unset($options[RequestOptions::BODY]);
        }

        if (!empty($options[RequestOptions::AUTH])) {
            $value = $options[RequestOptions::AUTH];
            $type = is_array($value)
                ? (isset($value[2]) ? strtolower($value[2]) : 'basic')
                : $value;
            $config[RequestOptions::AUTH] = $value;
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

        if (isset($options[RequestOptions::QUERY])) {
            $value = $options[RequestOptions::QUERY];
            if (is_array($value)) {
                $value = http_build_query($value, null, '&', PHP_QUERY_RFC3986);
            }
            if (!is_string($value)) {
                throw new \InvalidArgumentException('query must be a string or array');
            }
            $modify[RequestOptions::QUERY] = $value;
            unset($options[RequestOptions::QUERY]);
        }

        if (isset($options[RequestOptions::JSON])) {
            $modify[RequestOptions::BODY] = Psr7\stream_for(json_encode($options[RequestOptions::JSON]));
            $options['_conditional']['Content-Type'] = 'application/json';
            unset($options[RequestOptions::JSON]);
        }

        $request = Psr7\modify_request($request, $modify);
        if ($request->getBody() instanceof Psr7\MultipartStream) {
            // Use a multipart/form-data POST if a Content-Type is not set.
            $options['_conditional']['Content-Type'] = 'multipart/form-data; boundary='
                . $request->getBody()->getBoundary();
        }

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

    private function invalidBody()
    {
        throw new \InvalidArgumentException('Passing in the "body" request '
            . 'option as an array to send a POST request has been deprecated. '
            . 'Please use the "form_params" request option to send a '
            . 'application/x-www-form-urlencoded request, or a the "multipart" '
            . 'request option to send a multipart/form-data request.');
    }
}
