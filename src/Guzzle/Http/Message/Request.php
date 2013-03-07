<?php

namespace Guzzle\Http\Message;

use Guzzle\Common\Event;
use Guzzle\Common\Collection;
use Guzzle\Common\Exception\RuntimeException;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Exception\BadResponseException;
use Guzzle\Http\ClientInterface;
use Guzzle\Http\EntityBody;
use Guzzle\Http\EntityBodyInterface;
use Guzzle\Http\Url;
use Guzzle\Parser\ParserRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * HTTP request class to send requests
 */
class Request extends AbstractMessage implements RequestInterface
{
    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var Url HTTP Url
     */
    protected $url;

    /**
     * @var string HTTP method (GET, PUT, POST, DELETE, HEAD, OPTIONS, TRACE)
     */
    protected $method;

    /**
     * @var ClientInterface
     */
    protected $client;

    /**
     * @var Response Response of the request
     */
    protected $response;

    /**
     * @var EntityBodyInterface Response body
     */
    protected $responseBody;

    /**
     * @var string State of the request object
     */
    protected $state;

    /**
     * @var string Authentication username
     */
    protected $username;

    /**
     * @var string Auth password
     */
    protected $password;

    /**
     * @var Collection cURL specific transfer options
     */
    protected $curlOptions;

    /**
     * {@inheritdoc}
     */
    public static function getAllEvents()
    {
        return array(
            // Called when receiving or uploading data through cURL
            'curl.callback.read', 'curl.callback.write', 'curl.callback.progress',
            // Cloning a request
            'request.clone',
            // About to send the request, sent request, completed transaction
            'request.before_send', 'request.sent', 'request.complete',
            // A request received a successful response
            'request.success',
            // A request received an unsuccessful response
            'request.error',
            // An exception is being thrown because of an unsuccessful response
            'request.exception',
            // Received response status line
            'request.receive.status_line',
            // Manually set a response
            'request.set_response'
        );
    }

    /**
     * Create a new request
     *
     * @param string           $method  HTTP method
     * @param string|Url       $url     HTTP URL to connect to. The URI scheme, host header, and URI are parsed from the
     *                                  full URL. If query string parameters are present they will be parsed as well.
     * @param array|Collection $headers HTTP headers
     */
    public function __construct($method, $url, $headers = array())
    {
        $this->method = strtoupper($method);
        $this->curlOptions = new Collection();
        $this->params = new Collection();
        $this->setUrl($url);

        if ($headers) {
            // Special handling for multi-value headers
            foreach ($headers as $key => $value) {
                $lkey = strtolower($key);
                // Deal with collisions with Host and Authorization
                if ($lkey == 'host') {
                    $this->setHeader($key, $value);
                } elseif ($lkey == 'authorization') {
                    $parts = explode(' ', $value);
                    if ($parts[0] == 'Basic' && isset($parts[1])) {
                        list($user, $pass) = explode(':', base64_decode($parts[1]));
                        $this->setAuth($user, $pass);
                    } else {
                        $this->setHeader($key, $value);
                    }
                } else {
                    foreach ((array) $value as $v) {
                        $this->addHeader($key, $v);
                    }
                }
            }
        }

        $this->setState(self::STATE_NEW);
    }

    /**
     * Clone the request object, leaving off any response that was received
     * @see Guzzle\Plugin\Redirect\RedirectPlugin::cloneRequestWithGetMethod
     */
    public function __clone()
    {
        if ($this->eventDispatcher) {
            $this->eventDispatcher = clone $this->eventDispatcher;
        }
        $this->curlOptions = clone $this->curlOptions;
        $this->params = clone $this->params;
        // Remove state based parameters from the cloned request
        $this->params->remove('curl_handle')->remove('queued_response')->remove('curl_multi');
        $this->url = clone $this->url;
        $this->response = $this->responseBody = null;

        // Clone each header
        foreach ($this->headers as $key => &$value) {
            $value = clone $value;
        }

        $this->setState(RequestInterface::STATE_NEW);
        $this->dispatch('request.clone', array('request' => $this));
    }

    /**
     * Get the HTTP request as a string
     *
     * @return string
     */
    public function __toString()
    {
        return $this->getRawHeaders() . "\r\n\r\n";
    }

    /**
     * Default method that will throw exceptions if an unsuccessful response
     * is received.
     *
     * @param Event $event Received
     * @throws BadResponseException if the response is not successful
     */
    public static function onRequestError(Event $event)
    {
        $e = BadResponseException::factory($event['request'], $event['response']);
        $event['request']->dispatch('request.exception', array(
            'request'   => $event['request'],
            'response'  => $event['response'],
            'exception' => $e
        ));

        throw $e;
    }

    /**
     * {@inheritdoc}
     */
    public function setClient(ClientInterface $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function getRawHeaders()
    {
        $protocolVersion = $this->protocolVersion ?: '1.1';

        return trim($this->method . ' ' . $this->getResource()) . ' '
            . strtoupper(str_replace('https', 'http', $this->url->getScheme()))
            . '/' . $protocolVersion . "\r\n" . implode("\r\n", $this->getHeaderLines());
    }

    /**
     * {@inheritdoc}
     */
    public function setUrl($url)
    {
        if ($url instanceof Url) {
            $this->url = $url;
        } else {
            $this->url = Url::factory($url);
        }

        // Update the port and host header
        $this->setPort($this->url->getPort());

        if ($this->url->getUsername() || $this->url->getPassword()) {
            $this->setAuth($this->url->getUsername(), $this->url->getPassword());
            // Remove the auth info from the URL
            $this->url->setUsername(null);
            $this->url->setPassword(null);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function send()
    {
        if (!$this->client) {
            throw new RuntimeException('A client must be set on the request');
        }

        return $this->client->send($this);
    }

    /**
     * {@inheritdoc}
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * {@inheritdoc}
     */
    public function getQuery($asString = false)
    {
        return $asString
            ? (string) $this->url->getQuery()
            : $this->url->getQuery();
    }

    /**
     * {@inheritdoc}
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * {@inheritdoc}
     */
    public function getScheme()
    {
        return $this->url->getScheme();
    }

    /**
     * {@inheritdoc}
     */
    public function setScheme($scheme)
    {
        $this->url->setScheme($scheme);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getHost()
    {
        return $this->url->getHost();
    }

    /**
     * {@inheritdoc}
     */
    public function setHost($host)
    {
        $this->url->setHost($host);
        $this->setPort($this->url->getPort());

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getProtocolVersion()
    {
        return $this->protocolVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function setProtocolVersion($protocol)
    {
        $this->protocolVersion = $protocol;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath()
    {
        return '/' . ltrim($this->url->getPath(), '/');
    }

    /**
     * {@inheritdoc}
     */
    public function setPath($path)
    {
        $this->url->setPath($path);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getPort()
    {
        return $this->url->getPort();
    }

    /**
     * {@inheritdoc}
     */
    public function setPort($port)
    {
        $this->url->setPort($port);

        // Include the port in the Host header if it is not the default port for the scheme of the URL
        $scheme = $this->url->getScheme();
        if (($scheme == 'http' && $port != 80) || ($scheme == 'https' && $port != 443)) {
            $this->headers['host'] = new Header('Host', $this->url->getHost() . ':' . $port);
        } else {
            $this->headers['host'] = new Header('Host', $this->url->getHost());
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * {@inheritdoc}
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * {@inheritdoc}
     */
    public function setAuth($user, $password = '', $scheme = CURLAUTH_BASIC)
    {
        // If we got false or null, disable authentication
        if (!$user) {
            $this->password = $this->username = null;
            $this->removeHeader('Authorization');
            $this->getCurlOptions()->remove(CURLOPT_HTTPAUTH);
        } else {
            $this->username = $user;
            $this->password = $password;
            // Bypass CURL when using basic auth to promote connection reuse
            if ($scheme == CURLAUTH_BASIC) {
                $this->getCurlOptions()->remove(CURLOPT_HTTPAUTH);
                $this->setHeader('Authorization', 'Basic ' . base64_encode($this->username . ':' . $this->password));
            } else {
                $this->getCurlOptions()
                    ->set(CURLOPT_HTTPAUTH, $scheme)
                    ->set(CURLOPT_USERPWD, $this->username . ':' . $this->password);
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getResource()
    {
        $resource = $this->getPath();
        if ($query = (string) $this->url->getQuery()) {
            $resource .= '?' . $query;
        }

        return $resource;
    }

    /**
     * {@inheritdoc}
     */
    public function getUrl($asObject = false)
    {
        return $asObject ? clone $this->url : (string) $this->url;
    }

    /**
     * {@inheritdoc}
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * {@inheritdoc}
     */
    public function setState($state, array $context = array())
    {
        $this->state = $state;
        if ($this->state == self::STATE_NEW) {
            $this->response = null;
        } elseif ($this->state == self::STATE_COMPLETE) {
            $this->processResponse($context);
            $this->responseBody = null;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getCurlOptions()
    {
        return $this->curlOptions;
    }

    /**
     * {@inheritdoc}
     */
    public function receiveResponseHeader($data)
    {
        static $normalize = array("\r", "\n");
        $this->state = self::STATE_TRANSFER;
        $length = strlen($data);
        $data = str_replace($normalize, '', $data);

        if (strpos($data, 'HTTP/') === 0) {

            $startLine = explode(' ', $data, 3);
            $code = $startLine[1];
            $status = isset($startLine[2]) ? $startLine[2] : '';

            // Only download the body of the response to the specified response
            // body when a successful response is received.
            $body = $code >= 200 && $code < 300 ? $this->getResponseBody() : EntityBody::factory();

            $this->response = new Response($code, null, $body);
            $this->response->setStatus($code, $status)->setRequest($this);
            $this->dispatch('request.receive.status_line', array(
                'request'       => $this,
                'line'          => $data,
                'status_code'   => $code,
                'reason_phrase' => $status
            ));

        } elseif (strpos($data, ':') !== false) {

            list($header, $value) = explode(':', $data, 2);
            $this->response->addHeader(trim($header), trim($value));
        }

        return $length;
    }

    /**
     * {@inheritdoc}
     */
    public function setResponse(Response $response, $queued = false)
    {
        // Never overwrite the request associated with the response (useful for redirect history)
        if (!$response->getRequest()) {
            $response->setRequest($this);
        }

        if ($queued) {
            $this->getParams()->set('queued_response', $response);
        } else {
            $this->getParams()->remove('queued_response');
            $this->response = $response;
            $this->responseBody = $response->getBody();
            $this->processResponse();
        }

        $this->dispatch('request.set_response', $this->getEventArray());

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setResponseBody($body)
    {
        // Attempt to open a file for writing if a string was passed
        if (is_string($body)) {
            // @codeCoverageIgnoreStart
            if (!($body = fopen($body, 'w+'))) {
                throw new InvalidArgumentException('Could not open ' . $body . ' for writing');
            }
            // @codeCoverageIgnoreEnd
        }

        $this->responseBody = EntityBody::factory($body);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getResponseBody()
    {
        if ($this->responseBody === null) {
            $this->responseBody = EntityBody::factory();
        }

        return $this->responseBody;
    }

    /**
     * {@inheritdoc}
     */
    public function isResponseBodyRepeatable()
    {
        return !$this->responseBody ? true : $this->responseBody->isSeekable() && $this->responseBody->isReadable();
    }

    /**
     * {@inheritdoc}
     */
    public function getCookies()
    {
        if ($cookie = $this->getHeader('Cookie')) {
            $data = ParserRegistry::getInstance()->getParser('cookie')->parseCookie($cookie);
            return $data['cookies'];
        }

        return array();
    }

    /**
     * {@inheritdoc}
     */
    public function getCookie($name)
    {
        $cookies = $this->getCookies();

        return isset($cookies[$name]) ? $cookies[$name] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function addCookie($name, $value)
    {
        if (!$this->hasHeader('Cookie')) {
            $this->setHeader('Cookie', "{$name}={$value}");
        } else {
            $this->getHeader('Cookie')->add("{$name}={$value}");
        }

        // Always use semicolons to separate multiple cookie headers
        $this->getHeader('Cookie')->setGlue('; ');

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function removeCookie($name)
    {
        if ($cookie = $this->getHeader('Cookie')) {
            foreach ($cookie as $cookieValue) {
                if (strpos($cookieValue, $name . '=') === 0) {
                    $cookie->removeValue($cookieValue);
                }
            }
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function canCache()
    {
        // Only GET and HEAD requests can be cached
        if ($this->method != RequestInterface::GET && $this->method != RequestInterface::HEAD) {
            return false;
        }

        // Never cache requests when using no-store
        if ($this->hasCacheControlDirective('no-store')) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->eventDispatcher->addListener('request.error', array(__CLASS__, 'onRequestError'), -255);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getEventDispatcher()
    {
        if (!$this->eventDispatcher) {
            $this->setEventDispatcher(new EventDispatcher());
        }

        return $this->eventDispatcher;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($eventName, array $context = array())
    {
        $context['request'] = $this;
        $this->getEventDispatcher()->dispatch($eventName, new Event($context));
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function addSubscriber(EventSubscriberInterface $subscriber)
    {
        $this->getEventDispatcher()->addSubscriber($subscriber);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    protected function changedHeader($header)
    {
        parent::changedHeader($header);

        if ($header == 'host') {
            // If the Host header was changed, be sure to update the internal URL
            $this->setHost((string) $this->getHeader('Host'));
        }
    }

    /**
     * Get an array containing the request and response for event notifications
     *
     * @return array
     */
    protected function getEventArray()
    {
        return array(
            'request'  => $this,
            'response' => $this->response
        );
    }

    /**
     * Process a received response
     *
     * @param array $context Contextual information
     *
     * @throws BadResponseException on unsuccessful responses
     */
    protected function processResponse(array $context = array())
    {
        // Use the queued response if one is set
        if ($this->getParams()->get('queued_response')) {
            $this->response = $this->getParams()->get('queued_response');
            $this->responseBody = $this->response->getBody();
            $this->getParams()->remove('queued_response');
        } elseif (!$this->response) {
            // If no response, then processResponse shouldn't have been called
            $e = new RequestException('Error completing request');
            $e->setRequest($this);
            throw $e;
        }

        $this->state = self::STATE_COMPLETE;

        // A request was sent, but we don't know if we'll send more or if the final response will be successful
        $this->dispatch('request.sent', $this->getEventArray() + $context);

        // Some response processors will remove the response or reset the state (example: ExponentialBackoffPlugin)
        if ($this->state == RequestInterface::STATE_COMPLETE) {

            // The request completed, so the HTTP transaction is complete
            $this->dispatch('request.complete', $this->getEventArray());

            // If the response is bad, allow listeners to modify it or throw exceptions. You can change the response by
            // modifying the Event object in your listeners or calling setResponse() on the request
            if ($this->response->isError()) {
                $event = new Event($this->getEventArray());
                $this->getEventDispatcher()->dispatch('request.error', $event);
                // Allow events of request.error to quietly change the response
                if ($event['response'] !== $this->response) {
                    $this->response = $event['response'];
                }
            }

            // If a successful response was received, dispatch an event
            if ($this->response->isSuccessful()) {
                $this->dispatch('request.success', $this->getEventArray());
            }
        }

        return $this;
    }
}
