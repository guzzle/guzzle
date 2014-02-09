<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;
use Guzzle\Common\HasEmitterTrait;
use Guzzle\Http\Adapter\FakeBatchAdapter;
use Guzzle\Http\Adapter\BatchAdapterInterface;
use Guzzle\Http\Adapter\AdapterInterface;
use Guzzle\Http\Adapter\StreamAdapter;
use Guzzle\Http\Adapter\StreamingProxyAdapter;
use Guzzle\Http\Adapter\Curl\CurlAdapter;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\MessageFactoryInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Url\Url;

/**
 * HTTP client
 */
class Client implements ClientInterface
{
    use HasEmitterTrait;

    /** @var MessageFactoryInterface Request factory used by the client */
    private $messageFactory;

    /** @var AdapterInterface */
    private $adapter;

    /** @var BatchAdapterInterface */
    private $batchAdapter;

    /** @var Url Base URL of the client */
    private $baseUrl;

    /** @var Collection Configuration data */
    private $config;

    /**
     * Clients accept an array of constructor parameters.
     *
     * Here's an example of creating a client using a URI template for the
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
     *     - base_url: Base URL of the client that is merged into relative URLs. Can be a string or
     *       an array that contains a URI template followed by an associative array of
     *       expansion variables to inject into the URI template.
     *     - adapter: Adapter used to transfer requests
     *     - batch_adapter: Adapter used to transfer requests in parallel
     *     - message_factory: Factory used to create request and response object
     *     - defaults: Default request options to apply to each request
     */
    public function __construct(array $config = [])
    {
        $this->configureBaseUrl($config);
        $this->configureDefaults($config);
        $this->configureAdapter($config);
        $this->config = new Collection($config);
    }

    /**
     * Get the default User-Agent string to use with Guzzle
     *
     * @return string
     */
    public static function getDefaultUserAgent()
    {
        static $defaultAgent = '';
        if (!$defaultAgent) {
            $defaultAgent = 'Guzzle/' . \Guzzle\VERSION;
            if (extension_loaded('curl')) {
                $defaultAgent .= ' curl/' . curl_version()['version'];
            }
            $defaultAgent .= ' PHP/' . PHP_VERSION;
        }

        return $defaultAgent;
    }

    public function getConfig($keyOrPath = null)
    {
        return $keyOrPath === null ? $this->config : $this->config->getPath($keyOrPath);
    }

    public function setConfig($keyOrPath, $value)
    {
        // Ensure that "defaults" is always an array
        if ($keyOrPath == 'defaults' && !is_array($value)) {
            throw new \InvalidArgumentException('The value for "defaults" must be an array');
        }

        $this->config->setPath($keyOrPath, $value);
    }

    public function createRequest($method, $url = null, array $headers = [], $body = null, array $options = [])
    {
        // Merge in default options
        $options = array_replace_recursive($this->config['defaults'], $options);

        // Use a clone of the client's emitter
        $options['constructor_options'] = ['emitter' => clone $this->getEmitter()];

        $request = $this->messageFactory->createRequest(
            $method,
            $url ? (string) $this->buildUrl($url) : (string) $this->baseUrl,
            $headers,
            $body,
            $options
        );

        return $request;
    }

    public function get($url = null, array $headers = [], $options = [])
    {
        return $this->send($this->createRequest('GET', $url, $headers, null, $options));
    }

    public function head($url = null, array $headers = [], array $options = [])
    {
        return $this->send($this->createRequest('HEAD', $url, $headers, null, $options));
    }

    public function delete($url = null, array $headers = [], array $options = [])
    {
        return $this->send($this->createRequest('DELETE', $url, $headers, null, $options));
    }

    public function put($url = null, array $headers = [], $body = null, array $options = [])
    {
        return $this->send($this->createRequest('PUT', $url, $headers, $body, $options));
    }

    public function patch($url = null, array $headers = [], $body = null, array $options = [])
    {
        return $this->send($this->createRequest('PATCH', $url, $headers, $body, $options));
    }

    public function post($url = null, array $headers = [], $body = null, array $options = [])
    {
        return $this->send($this->createRequest('POST', $url, $headers, $body, $options));
    }

    public function options($url = null, array $headers = [], array $options = [])
    {
        return $this->send($this->createRequest('OPTIONS', $url, $headers, null, $options));
    }

    public function send(RequestInterface $request)
    {
        $transaction = new Transaction($this, $request);
        if ($response = $this->adapter->send($transaction)) {
            return $response;
        }

        throw new \RuntimeException('No response was associated with the transaction');
    }

    public function sendAll($requests, array $options = [])
    {
        if (!($requests instanceof TransactionIterator)) {
            $requests = new TransactionIterator($requests, $this, $options);
        }

        $this->batchAdapter->batch(
            $requests,
            isset($options['parallel']) ? $options['parallel'] : 50
        );
    }

    /**
     * Get an array of default options to apply to the client
     *
     * @return array
     */
    protected function getDefaultOptions()
    {
        return [
            'allow_redirects' => true,
            'exceptions'      => true,
            'verify'          => __DIR__ . '/cacert.pem'
        ];
    }

    /**
     * Expand a URI template and inherit from the base URL if it's relative
     *
     * @param string|array $url URL or URI template to expand
     *
     * @return string
     */
    private function buildUrl($url)
    {
        if (!is_array($url)) {
            if (substr($url, 0, 4) === 'http') {
                return (string) $url;
            }
            $baseUrl = clone $this->baseUrl;
            return (string) $baseUrl->combine($url);
        } elseif (substr($url[0], 0, 4) == 'http') {
            return \Guzzle\uriTemplate($url[0], $url[1]);
        }

        $baseUrl = clone $this->baseUrl;
        return (string) $baseUrl->combine(
            \Guzzle\uriTemplate($url[0], $url[1])
        );
    }

    /**
     * Get a default batch adapter to use based on the environment
     *
     * @return BatchAdapterInterface|null
     * @throws \RuntimeException
     */
    private function getDefaultBatchAdapter()
    {
        return extension_loaded('curl')
            ? new CurlAdapter($this->messageFactory)
            : new FakeBatchAdapter($this->adapter);
    }

    /**
     * Get a default adapter to use based on the environment
     *
     * @return AdapterInterface
     * @throws \RuntimeException
     */
    private function getDefaultAdapter()
    {
        if (extension_loaded('curl')) {
            if (!ini_get('allow_url_fopen')) {
                return $this->batchAdapter = $this->adapter = new CurlAdapter($this->messageFactory);
            } else {
                // Assume that the batch adapter will also be this CurlAdapter
                $this->batchAdapter = new CurlAdapter($this->messageFactory);
                return new StreamingProxyAdapter(
                    $this->batchAdapter,
                    new StreamAdapter($this->messageFactory)
                );
            }
        } elseif (ini_get('allow_url_fopen')) {
            return new StreamAdapter($this->messageFactory);
        } else {
            throw new \RuntimeException('The curl extension must be installed or you must set allow_url_fopen to true');
        }
    }

    private function configureBaseUrl(&$config)
    {
        if (!isset($config['base_url'])) {
            $this->baseUrl = new Url('', '');
        } elseif (is_array($config['base_url'])) {
            $this->baseUrl = Url::fromString(\Guzzle\uriTemplate($config['base_url'][0], $config['base_url'][1]));
            $config['base_url'] = (string) $this->baseUrl;
        } else {
            $this->baseUrl = Url::fromString($config['base_url']);
        }
    }

    private function configureDefaults(&$config)
    {
        if (isset($config['defaults'])) {
            $config['defaults'] = array_replace($this->getDefaultOptions(), $config['defaults']);
        } else {
            $config['defaults'] = $this->getDefaultOptions();
        }

        // Add the default user-agent header
        if (!isset($config['defaults']['headers'])) {
            $config['defaults']['headers'] = ['User-Agent' => static::getDefaultUserAgent()];
        } elseif (!isset(array_change_key_case($config['defaults']['headers'])['user-agent'])) {
            // Add the User-Agent header if one was not already set
            $config['defaults']['headers']['User-Agent'] = static::getDefaultUserAgent();
        }
    }

    private function configureAdapter(&$config)
    {
        if (isset($config['message_factory'])) {
            $this->messageFactory = $config['message_factory'];
            unset($config['message_factory']);
        } else {
            $this->messageFactory = new MessageFactory();
        }
        if (isset($config['adapter'])) {
            $this->adapter = $config['adapter'];
            unset($config['adapter']);
        } else {
            $this->adapter = $this->getDefaultAdapter();
        }
        // If no batch adapter was explicitly provided and one was not defaulted
        // when creating the default adapter, then create one now
        if (isset($config['batch_adapter'])) {
            $this->batchAdapter = $config['batch_adapter'];
            unset($config['batch_adapter']);
        } elseif (!$this->batchAdapter) {
            $this->batchAdapter = $this->getDefaultBatchAdapter();
        }
    }
}
