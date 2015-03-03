<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\MessageFactoryInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\FutureResponse;
use GuzzleHttp\Ring\Core;
use GuzzleHttp\Ring\Future\FutureInterface;
use GuzzleHttp\Exception\RequestException;
use React\Promise\FulfilledPromise;
use React\Promise\RejectedPromise;

/**
 * HTTP client
 */
class Client implements ClientInterface
{
    use HasEmitterTrait;

    /** @var MessageFactoryInterface Request factory used by the client */
    private $messageFactory;

    /** @var Url Base URL of the client */
    private $baseUrl;

    /** @var array Default request options */
    private $defaults;

    /** @var callable Request state machine */
    private $fsm;

    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using an URI template for the
     * client's base_url and an array of default request options to apply
     * to each request:
     *
     *     $client = new Client([
     *         'base_url' => [
     *              'http://www.foo.com/{version}/',
     *              ['version' => '123']
     *          ],
     *         'defaults' => [
     *             'timeout'         => 10,
     *             'allow_redirects' => false,
     *             'proxy'           => '192.168.16.1:10'
     *         ]
     *     ]);
     *
     * @param array $config Client configuration settings
     *     - base_url: Base URL of the client that is merged into relative URLs.
     *       Can be a string or an array that contains a URI template followed
     *       by an associative array of expansion variables to inject into the
     *       URI template.
     *     - handler: callable RingPHP handler used to transfer requests
     *     - message_factory: Factory used to create request and response object
     *     - defaults: Default request options to apply to each request
     *     - emitter: Event emitter used for request events
     *     - fsm: (internal use only) The request finite state machine. A
     *       function that accepts a transaction and optional final state. The
     *       function is responsible for transitioning a request through its
     *       lifecycle events.
     */
    public function __construct(array $config = [])
    {
        $this->configureBaseUrl($config);
        $this->configureDefaults($config);

        if (isset($config['emitter'])) {
            $this->emitter = $config['emitter'];
        }

        $this->messageFactory = isset($config['message_factory'])
            ? $config['message_factory']
            : new MessageFactory();

        if (isset($config['fsm'])) {
            $this->fsm = $config['fsm'];
        } else {
            if (isset($config['handler'])) {
                $handler = $config['handler'];
            } elseif (isset($config['adapter'])) {
                $handler = $config['adapter'];
            } else {
                $handler = Utils::getDefaultHandler();
            }
            $this->fsm = new RequestFsm($handler, $this->messageFactory);
        }
    }

    public function getDefaultOption($keyOrPath = null)
    {
        return $keyOrPath === null
            ? $this->defaults
            : Utils::getPath($this->defaults, $keyOrPath);
    }

    public function setDefaultOption($keyOrPath, $value)
    {
        Utils::setPath($this->defaults, $keyOrPath, $value);
    }

    public function getBaseUrl()
    {
        return (string) $this->baseUrl;
    }

    public function createRequest($method, $url = null, array $options = [])
    {
        $options = $this->mergeDefaults($options);
        // Use a clone of the client's emitter
        $options['config']['emitter'] = clone $this->getEmitter();
        $url = $url || (is_string($url) && strlen($url))
            ? $this->buildUrl($url)
            : (string) $this->baseUrl;

        return $this->messageFactory->createRequest($method, $url, $options);
    }

    public function get($url = null, $options = [])
    {
        return $this->send($this->createRequest('GET', $url, $options));
    }

    public function head($url = null, array $options = [])
    {
        return $this->send($this->createRequest('HEAD', $url, $options));
    }

    public function delete($url = null, array $options = [])
    {
        return $this->send($this->createRequest('DELETE', $url, $options));
    }

    public function put($url = null, array $options = [])
    {
        return $this->send($this->createRequest('PUT', $url, $options));
    }

    public function patch($url = null, array $options = [])
    {
        return $this->send($this->createRequest('PATCH', $url, $options));
    }

    public function post($url = null, array $options = [])
    {
        return $this->send($this->createRequest('POST', $url, $options));
    }

    public function options($url = null, array $options = [])
    {
        return $this->send($this->createRequest('OPTIONS', $url, $options));
    }

    public function send(RequestInterface $request)
    {
        $isFuture = $request->getConfig()->get('future');
        $trans = new Transaction($this, $request, $isFuture);
        $fn = $this->fsm;

        try {
            $fn($trans);
            if ($isFuture) {
                // Turn the normal response into a future if needed.
                return $trans->response instanceof FutureInterface
                    ? $trans->response
                    : new FutureResponse(new FulfilledPromise($trans->response));
            }
            // Resolve deep futures if this is not a future
            // transaction. This accounts for things like retries
            // that do not have an immediate side-effect.
            while ($trans->response instanceof FutureInterface) {
                $trans->response = $trans->response->wait();
            }
            return $trans->response;
        } catch (\Exception $e) {
            if ($isFuture) {
                // Wrap the exception in a promise
                return new FutureResponse(new RejectedPromise($e));
            }
            throw RequestException::wrapException($trans->request, $e);
        }
    }

    /**
     * Get an array of default options to apply to the client
     *
     * @return array
     */
    protected function getDefaultOptions()
    {
        $settings = [
            'allow_redirects' => true,
            'exceptions'      => true,
            'decode_content'  => true,
            'verify'          => true
        ];

        // Use the standard Linux HTTP_PROXY and HTTPS_PROXY if set
        if ($proxy = getenv('HTTP_PROXY')) {
            $settings['proxy']['http'] = $proxy;
        }

        if ($proxy = getenv('HTTPS_PROXY')) {
            $settings['proxy']['https'] = $proxy;
        }

        return $settings;
    }

    /**
     * Expand a URI template and inherit from the base URL if it's relative
     *
     * @param string|array $url URL or an array of the URI template to expand
     *                          followed by a hash of template varnames.
     * @return string
     * @throws \InvalidArgumentException
     */
    private function buildUrl($url)
    {
        // URI template (absolute or relative)
        if (!is_array($url)) {
            return strpos($url, '://')
                ? (string) $url
                : (string) $this->baseUrl->combine($url);
        }

        if (!isset($url[1])) {
            throw new \InvalidArgumentException('You must provide a hash of '
                . 'varname options in the second element of a URL array.');
        }

        // Absolute URL
        if (strpos($url[0], '://')) {
            return Utils::uriTemplate($url[0], $url[1]);
        }

        // Combine the relative URL with the base URL
        return (string) $this->baseUrl->combine(
            Utils::uriTemplate($url[0], $url[1])
        );
    }

    private function configureBaseUrl(&$config)
    {
        if (!isset($config['base_url'])) {
            $this->baseUrl = new Url('', '');
        } elseif (!is_array($config['base_url'])) {
            $this->baseUrl = Url::fromString($config['base_url']);
        } elseif (count($config['base_url']) < 2) {
            throw new \InvalidArgumentException('You must provide a hash of '
                . 'varname options in the second element of a base_url array.');
        } else {
            $this->baseUrl = Url::fromString(
                Utils::uriTemplate(
                    $config['base_url'][0],
                    $config['base_url'][1]
                )
            );
            $config['base_url'] = (string) $this->baseUrl;
        }
    }

    private function configureDefaults($config)
    {
        if (!isset($config['defaults'])) {
            $this->defaults = $this->getDefaultOptions();
        } else {
            $this->defaults = array_replace(
                $this->getDefaultOptions(),
                $config['defaults']
            );
        }

        // Add the default user-agent header
        if (!isset($this->defaults['headers'])) {
            $this->defaults['headers'] = [
                'User-Agent' => Utils::getDefaultUserAgent()
            ];
        } elseif (!Core::hasHeader($this->defaults, 'User-Agent')) {
            // Add the User-Agent header if one was not already set
            $this->defaults['headers']['User-Agent'] = Utils::getDefaultUserAgent();
        }
    }

    /**
     * Merges default options into the array passed by reference.
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
            // Create a set of lowercased keys that are present.
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
     * @deprecated Use {@see GuzzleHttp\Pool} instead.
     * @see GuzzleHttp\Pool
     */
    public function sendAll($requests, array $options = [])
    {
        Pool::send($this, $requests, $options);
    }

    /**
     * @deprecated Use GuzzleHttp\Utils::getDefaultHandler
     */
    public static function getDefaultHandler()
    {
        return Utils::getDefaultHandler();
    }

    /**
     * @deprecated Use GuzzleHttp\Utils::getDefaultUserAgent
     */
    public static function getDefaultUserAgent()
    {
        return Utils::getDefaultUserAgent();
    }
}
