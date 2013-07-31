<?php

namespace Guzzle\Http;

use Guzzle\Common\Collection;
use Guzzle\Common\HasDispatcher;
use Guzzle\Common\Exception\RuntimeException;
use Guzzle\Common\Version;
use Guzzle\Http\Adapter\Curl\CurlAdapter;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Url\Url;
use Guzzle\Url\UriTemplate;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\MessageFactoryInterface;

/**
 * HTTP client
 */
class Client implements ClientInterface
{
    use HasDispatcher;

    const REQUEST_OPTIONS = 'request.options';

    /** @var string The user agent string to set on each request */
    protected $userAgent;

    /** @var Collection Parameter object holding configuration data */
    private $config;

    /** @var Url Base URL of the client */
    private $baseUrl;

    /** @var AdapterInterface */
    private $adapter;

    /** @var UriTemplateInterface URI template owned by the client */
    private $uriTemplate;

    /** @var MessageFactoryInterface Request factory used by the client */
    protected $messageFactory;

    /**
     * @param array $config Client configuration settings
     */
    public function __construct(array $config = array())
    {
        $this->config = new Collection($config);
        $this->baseUrl = $this->config['base_url'];
        $this->messageFactory = $config['message_factory'] ?: MessageFactory::getInstance();
        $this->userAgent = $this->getDefaultUserAgent();
        if ($this->config['adapter']) {
            $this->adapter = $this->config['adapter'];
        } else {
            $this->adapter = new CurlAdapter($this->messageFactory);
        }
    }

    public function getConfig($key)
    {
        return $this->config->getPath($key);
    }

    /**
     * Set a default request option on the client that will be used as a default for each request
     *
     * @param string $keyOrPath request.options key (e.g. allow_redirects) or path to a nested key (e.g. headers/foo)
     * @param mixed  $value     Value to set
     *
     * @return $this
     */
    public function setDefaultOption($keyOrPath, $value)
    {
        $this->config->setPath(self::REQUEST_OPTIONS . '/' . $keyOrPath, $value);

        return $this;
    }

    /**
     * Retrieve a default request option from the client
     *
     * @param string $keyOrPath request.options key (e.g. allow_redirects) or path to a nested key (e.g. headers/foo)
     *
     * @return mixed|null
     */
    public function getDefaultOption($keyOrPath)
    {
        return $this->config->getPath(self::REQUEST_OPTIONS . '/' . $keyOrPath);
    }

    public function createRequest($method, $url = null, $body = null, array $options = array())
    {
        $url = !$url ? $this->getBaseUrl() : $this->buildUrl($url);

        // Merge in default options
        if ($default = $this->config->get(self::REQUEST_OPTIONS)) {
            $options = array_replace_recursive($default, $options);
        }

        $request = $this->messageFactory->createRequest($method, (string) $url, $body, $options);
        $request->setEventDispatcher(clone $this->getEventDispatcher());
        if ($this->userAgent && !$request->hasHeader('User-Agent')) {
            $request->setHeader('User-Agent', $this->userAgent);
        }

        return $request;
    }

    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    public function setUserAgent($userAgent, $includeDefault = false)
    {
        if ($includeDefault) {
            $userAgent .= ' ' . $this->getDefaultUserAgent();
        }
        $this->userAgent = $userAgent;

        return $this;
    }

    public function get($uri = null, $options = array())
    {
        return $this->send($this->createRequest('GET', $uri, null, $options));
    }

    public function head($uri = null, array $options = array())
    {
        return $this->send($this->createRequest('HEAD', $uri, null, $options));
    }

    public function delete($uri = null, array $options = array())
    {
        return $this->send($this->createRequest('DELETE', $uri, null, $options));
    }

    public function put($uri = null, $body = null, array $options = array())
    {
        return $this->send($this->createRequest('PUT', $uri, $body, $options));
    }

    public function patch($uri = null, $body = null, array $options = array())
    {
        return $this->send($this->createRequest('PATCH', $uri, $body, $options));
    }

    public function post($uri = null, $body = null, array $options = array())
    {
        return $this->send($this->createRequest('POST', $uri, $body, $options));
    }

    public function options($uri = null, array $options = array())
    {
        return $this->send($this->createRequest('OPTIONS', $uri, $options));
    }

    public function send(RequestInterface $request)
    {

    }

    public function batch($requests)
    {

    }

    /**
     * Get the default User-Agent string to use with Guzzle
     *
     * @return string
     */
    protected function getDefaultUserAgent()
    {
        return 'Guzzle/' . Version::VERSION
        . ' curl/' . CurlVersion::getInstance()->get('version')
        . ' PHP/' . PHP_VERSION;
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
        /*
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
        */
    }

    /**
     * Expand a URI template
     *
     * @param string $template  Template to expand
     * @param array  $variables Variables to inject
     *
     * @return string
     */
    protected function expandTemplate($template, array $variables = array())
    {
        return function_exists('uri_template')
            ? uri_template($template, $variables)
            : UriTemplate::getInstance()->expand($template, $variables);
    }

    /**
     * Initializes SSL settings
     */
    private function initSsl()
    {
        /*
        if ('system' == ($authority = $this->config[self::SSL_CERT_AUTHORITY])) {
            return;
        }

        if ($authority === null) {
            $authority = true;
        }

        if ($authority === true && substr(__FILE__, 0, 7) == 'phar://') {
            $authority = $this->preparePharCacert();
            $that = $this;
            $this->getEventDispatcher()->addListener('request.before_send', function ($event) use ($authority, $that) {
                if ($authority == $event['request']->getCurlOptions()->get(CURLOPT_CAINFO)) {
                    $that->preparePharCacert(false);
                }
            });
        }

        $this->setSslVerification($authority);
        */
    }

    private function buildUrl($url)
    {
        if (!$url) {
            return '';
        }

        if (!is_array($uri)) {
            $templateVars = null;
        } else {
            list($uri, $templateVars) = $uri;
        }

        if (substr($uri, 0, 4) === 'http') {
            // Use absolute URLs as-is
            $url = $this->expandTemplate($uri, $templateVars);
        } else {
            $url = Url::fromString($this->getBaseUrl())->combine($this->expandTemplate($uri, $templateVars));
        }

        return $url;
    }
}
