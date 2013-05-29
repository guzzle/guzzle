<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;
use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Common\Exception\ExceptionCollection;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Exception\RuntimeException;
use Guzzle\Common\Version;
use Guzzle\Parser\ParserRegistry;
use Guzzle\Parser\UriTemplate\UriTemplateInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\RequestFactoryInterface;
use Guzzle\Http\Curl\CurlMultiInterface;
use Guzzle\Http\Curl\CurlMultiProxy;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Http\Curl\CurlVersion;

/**
 * HTTP client
 */
class Client extends AbstractHasDispatcher implements ClientInterface
{
    const REQUEST_PARAMS = 'request.params';
    const CURL_OPTIONS = 'curl.options';
    const SSL_CERT_AUTHORITY = 'ssl.certificate_authority';
    const DISABLE_REDIRECTS = RedirectPlugin::DISABLE;

    /** @var Collection Default HTTP headers to set on each request */
    protected $defaultHeaders;

    /** @var string The user agent string to set on each request */
    protected $userAgent;

    /** @var Collection Parameter object holding configuration data */
    private $config;

    /** @var Url Base URL of the client */
    private $baseUrl;

    /** @var CurlMultiInterface CurlMulti object used internally */
    private $curlMulti;

    /** @var UriTemplateInterface URI template owned by the client */
    private $uriTemplate;

    /** @var RequestFactoryInterface Request factory used by the client */
    protected $requestFactory;

    public static function getAllEvents()
    {
        return array(self::CREATE_REQUEST);
    }

    /**
     * @param string           $baseUrl Base URL of the web service
     * @param array|Collection $config  Configuration settings
     */
    public function __construct($baseUrl = '', $config = null)
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The PHP cURL extension must be installed to use Guzzle.');
        }
        $this->setConfig($config ?: new Collection());
        $this->initSsl();
        $this->setBaseUrl($baseUrl);
        $this->defaultHeaders = new Collection();
        $this->setRequestFactory(RequestFactory::getInstance());

        // Redirect by default, but allow for redirects to be globally disabled on a client
        if (!$this->config->get(self::DISABLE_REDIRECTS)) {
            $this->addSubscriber(new RedirectPlugin());
        }

        // Set the default User-Agent on the client
        $this->userAgent = $this->getDefaultUserAgent();
    }

    final public function setConfig($config)
    {
        // Set the configuration object
        if ($config instanceof Collection) {
            $this->config = $config;
        } elseif (is_array($config)) {
            $this->config = new Collection($config);
        } else {
            throw new InvalidArgumentException(
                'Config must be an array or Collection'
            );
        }

        return $this;
    }

    final public function getConfig($key = false)
    {
        return $key ? $this->config->get($key) : $this->config;
    }

    final public function setSslVerification($certificateAuthority = true, $verifyPeer = true, $verifyHost = 2)
    {
        $opts = $this->config->get(self::CURL_OPTIONS) ?: array();

        if ($certificateAuthority === true) {
            // use bundled CA bundle, set secure defaults
            $opts[CURLOPT_CAINFO] = __DIR__ . '/Resources/cacert.pem';
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        } elseif ($certificateAuthority === false) {
            unset($opts[CURLOPT_CAINFO]);
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        } elseif ($verifyPeer !== true && $verifyPeer !== false && $verifyPeer !== 1 && $verifyPeer !== 0) {
            throw new InvalidArgumentException('verifyPeer must be 1, 0 or boolean');
        } elseif ($verifyHost !== 0 && $verifyHost !== 1 && $verifyHost !== 2) {
            throw new InvalidArgumentException('verifyHost must be 0, 1 or 2');
        } else {
            $opts[CURLOPT_SSL_VERIFYPEER] = $verifyPeer;
            $opts[CURLOPT_SSL_VERIFYHOST] = $verifyHost;
            if (is_file($certificateAuthority)) {
                unset($opts[CURLOPT_CAPATH]);
                $opts[CURLOPT_CAINFO] = $certificateAuthority;
            } elseif (is_dir($certificateAuthority)) {
                unset($opts[CURLOPT_CAINFO]);
                $opts[CURLOPT_CAPATH] = $certificateAuthority;
            } else {
                throw new RuntimeException(
                    'Invalid option passed to ' . self::SSL_CERT_AUTHORITY . ': ' . $certificateAuthority
                );
            }
        }

        $this->config->set(self::CURL_OPTIONS, $opts);

        return $this;
    }

    public function getDefaultHeaders()
    {
        return $this->defaultHeaders;
    }

    public function setDefaultHeaders($headers)
    {
        if ($headers instanceof Collection) {
            $this->defaultHeaders = $headers;
        } elseif (is_array($headers)) {
            $this->defaultHeaders = new Collection($headers);
        } else {
            throw new InvalidArgumentException('Headers must be an array or Collection');
        }

        return $this;
    }

    public function expandTemplate($template, array $variables = null)
    {
        $expansionVars = $this->getConfig()->getAll();
        if ($variables) {
            $expansionVars = array_merge($expansionVars, $variables);
        }

        return $this->getUriTemplate()->expand($template, $expansionVars);
    }

    /**
     * Set the URI template expander to use with the client
     *
     * @param UriTemplateInterface $uriTemplate URI template expander
     *
     * @return self
     */
    public function setUriTemplate(UriTemplateInterface $uriTemplate)
    {
        $this->uriTemplate = $uriTemplate;

        return $this;
    }

    /**
     * Get the URI template expander used by the client
     *
     * @return UriTemplateInterface
     */
    public function getUriTemplate()
    {
        if (!$this->uriTemplate) {
            $this->uriTemplate = ParserRegistry::getInstance()->getParser('uri_template');
        }

        return $this->uriTemplate;
    }

    public function createRequest($method = RequestInterface::GET, $uri = null, $headers = null, $body = null)
    {
        if (!is_array($uri)) {
            $templateVars = null;
        } else {
            if (count($uri) != 2 || !isset($uri[1]) || !is_array($uri[1])) {
                throw new InvalidArgumentException(
                    'You must provide a URI template followed by an array of template variables '
                    . 'when using an array for a URI template'
                );
            }
            list($uri, $templateVars) = $uri;
        }

        if (!$uri) {
            $url = $this->getBaseUrl();
        } elseif (substr($uri, 0, 4) === 'http') {
            // Use absolute URLs as-is
            $url = $this->expandTemplate($uri, $templateVars);
        } else {
            $url = Url::factory($this->getBaseUrl())->combine($this->expandTemplate($uri, $templateVars));
        }

        // If default headers are provided, then merge them into existing headers
        // If a collision occurs, the header is completely replaced
        if (count($this->defaultHeaders)) {
            if (is_array($headers)) {
                $headers = array_merge($this->defaultHeaders->getAll(), $headers);
            } elseif ($headers instanceof Collection) {
                $headers = array_merge($this->defaultHeaders->getAll(), $headers->getAll());
            } else {
                $headers = $this->defaultHeaders;
            }
        }

        return $this->prepareRequest(
            $this->requestFactory->create($method, (string) $url, $headers, $body)
        );
    }

    public function getBaseUrl($expand = true)
    {
        return $expand ? $this->expandTemplate($this->baseUrl) : $this->baseUrl;
    }

    public function setBaseUrl($url)
    {
        $this->baseUrl = $url;

        return $this;
    }

    public function setUserAgent($userAgent, $includeDefault = false)
    {
        if ($includeDefault) {
            $userAgent .= ' ' . $this->getDefaultUserAgent();
        }
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * Get the default User-Agent string to use with Guzzle
     *
     * @return string
     */
    public function getDefaultUserAgent()
    {
        return 'Guzzle/' . Version::VERSION
            . ' curl/' . CurlVersion::getInstance()->get('version')
            . ' PHP/' . PHP_VERSION;
    }

    public function get($uri = null, $headers = null, $body = null)
    {
        return $this->createRequest('GET', $uri, $headers, $body);
    }

    public function head($uri = null, $headers = null)
    {
        return $this->createRequest('HEAD', $uri, $headers);
    }

    public function delete($uri = null, $headers = null, $body = null)
    {
        return $this->createRequest('DELETE', $uri, $headers, $body);
    }

    public function put($uri = null, $headers = null, $body = null)
    {
        return $this->createRequest('PUT', $uri, $headers, $body);
    }

    public function patch($uri = null, $headers = null, $body = null)
    {
        return $this->createRequest('PATCH', $uri, $headers, $body);
    }

    public function post($uri = null, $headers = null, $postBody = null)
    {
        return $this->createRequest('POST', $uri, $headers, $postBody);
    }

    public function options($uri = null)
    {
        return $this->createRequest('OPTIONS', $uri);
    }

    public function send($requests)
    {
        $curlMulti = $this->getCurlMulti();
        $multipleRequests = !($requests instanceof RequestInterface);
        if (!$multipleRequests) {
            $requests = array($requests);
        }

        foreach ($requests as $request) {
            $curlMulti->add($request);
        }

        try {
            $curlMulti->send();
        } catch (ExceptionCollection $e) {
            throw $multipleRequests ? $e : $e->getFirst();
        }

        if (!$multipleRequests) {
            return end($requests)->getResponse();
        } else {
            return array_map(function ($request) { return $request->getResponse(); }, $requests);
        }
    }

    /**
     * Set a curl multi object to be used internally by the client for transferring requests.
     *
     * @param CurlMultiInterface $curlMulti Multi object
     *
     * @return self
     */
    public function setCurlMulti(CurlMultiInterface $curlMulti)
    {
        $this->curlMulti = $curlMulti;

        return $this;
    }

    public function getCurlMulti()
    {
        if (!$this->curlMulti) {
            $this->curlMulti = new CurlMultiProxy();
        }

        return $this->curlMulti;
    }

    public function setRequestFactory(RequestFactoryInterface $factory)
    {
        $this->requestFactory = $factory;

        return $this;
    }

    /**
     * Copy the cacert.pem file from the phar if it is not in the temp folder and validate the MD5 checksum
     *
     * @param bool $md5Check Set to false to not perform the MD5 validation
     *
     * @return string Returns the path to the extracted cacert
     * @throws RuntimeException if the file cannot be copied or there is a MD5 mismatch
     */
    public function preparePharCacert($md5Check = true)
    {
        $from = __DIR__ . '/Resources/cacert.pem';
        $certFile = sys_get_temp_dir() . '/guzzle-cacert.pem';
        if (!file_exists($certFile) && !copy($from, $certFile)) {
            throw new RuntimeException("Could not copy {$from} to {$certFile}: " . var_export(error_get_last(), true));
        } elseif ($md5Check) {
            $actualMd5 = md5_file($certFile);
            $expectedMd5 = trim(file_get_contents("{$from}.md5"));
            if ($actualMd5 != $expectedMd5) {
                throw new RuntimeException("{$certFile} MD5 mismatch: expected {$expectedMd5} but got {$actualMd5}");
            }
        }

        return $certFile;
    }

    /**
     * Prepare a request to be sent from the Client by adding client specific behaviors and properties to the request.
     *
     * @param RequestInterface $request Request to prepare for the client
     *
     * @return RequestInterface
     */
    protected function prepareRequest(RequestInterface $request)
    {
        $request->setClient($this);

        // Add any curl options to the request
        if ($options = $this->config->get(self::CURL_OPTIONS)) {
            $request->getCurlOptions()->merge(CurlHandle::parseCurlConfig($options));
        }

        // Add request parameters to the request
        if ($options = $this->config->get(self::REQUEST_PARAMS)) {
            $request->getParams()->merge($options);
        }

        // Attach client observers to the request
        $request->setEventDispatcher(clone $this->getEventDispatcher());

        // Set the User-Agent if one is specified on the client but not explicitly on the request
        if ($this->userAgent && !$request->hasHeader('User-Agent')) {
            $request->setHeader('User-Agent', $this->userAgent);
        }

        $this->dispatch(
            'client.create_request',
            array(
                'client'  => $this,
                'request' => $request
            )
        );

        return $request;
    }

    /**
     * Initializes SSL settings
     */
    protected function initSsl()
    {
        // Allow ssl.certificate_authority config setting to control the certificate authority used by curl
        $authority = $this->config->get(self::SSL_CERT_AUTHORITY);

        // Set the SSL certificate
        if ($authority !== 'system') {

            if ($authority === null) {
                $authority = true;
            }

            if ($authority === true && substr(__FILE__, 0, 7) == 'phar://') {
                $authority = $this->preparePharCacert();
                $that = $this;
                $this->getEventDispatcher()->addListener(
                    'request.before_send',
                    function ($event) use ($authority, $that) {
                        if ($authority == $event['request']->getCurlOptions()->get(CURLOPT_CAINFO)) {
                            $that->preparePharCacert(false);
                        }
                    }
                );
            }

            $this->setSslVerification($authority);
        }
    }
}
