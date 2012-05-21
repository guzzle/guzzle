<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Common\Cache\CacheAdapterInterface;
use Guzzle\Common\Event;
use Guzzle\Http\Utils;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Exception\BadResponseException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin to enable the caching of GET and HEAD requests.  Caching can be done
 * on all requests passing through this plugin or only after retrieving
 * resources with cacheable response headers.  This is a simple implementation
 * of RFC 2616 and should be considered a private transparent proxy cache
 * (authorization and private data can be cached).
 */
class CachePlugin implements EventSubscriberInterface
{
    /**
     * @var CacheAdapter Cache adapter used to write cache data to cache objects
     */
    private $adapter;

    /**
     * @var bool Whether or not cache items are serialized when storing
     */
    private $serialize;

    /**
     * @var int Default cached item lifetime if Response headers are not used
     */
    private $defaultLifetime = 3600;

    /**
     * @var array Array of request cache keys to hold until a response is returned
     */
    private $cached = array();

    /**
     * @var array Headers are excluded from the caching (see RFC 2616:13.5.1)
     */
    protected $excludeResponseHeaders = array(
        'Connection', 'Keep-Alive', 'Proxy-Authenticate', 'Proxy-Authorization',
        'TE', 'Trailers', 'Transfer-Encoding', 'Upgrade', 'Set-Cookie',
        'Set-Cookie2'
    );

    /**
     * Construct a new CachePlugin
     *
     * @param CacheAdapterInterface $adapter         Cache adapter to write and read cache data
     * @param bool                  $serialize       Set to TRUE to serialize data before writing
     * @param int                   $defaultLifetime The number of seconds that a cache entry
     *     should be considered fresh when no explicit freshness information is provided
     *     in a response. Explicit Cache-Control or Expires headers override this value
     */
    public function __construct(CacheAdapterInterface $adapter, $serialize = false, $defaultLifetime = 3600)
    {
        $this->adapter = $adapter;
        $this->serialize = (bool) $serialize;
        $this->defaultLifetime = (int) $defaultLifetime;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            'request.before_send' => array('onRequestBeforeSend', -255),
            'request.sent'        => array('onRequestSent', 255)
        );
    }

    /**
     * Get the cache adapter object
     *
     * @return CacheAdapter
     */
    public function getCacheAdapter()
    {
        return $this->adapter;
    }

    /**
     * Calculate the hash key of a request object
     *
     * @param RequestInterface $request Request to hash
     * @param string           $raw     Set to TRUE to retrieve the un-encoded string for debugging
     *
     * @return string
     */
    public function getCacheKey(RequestInterface $request, $raw = false)
    {
        // See if the key has already been calculated
        $key = $request->getParams()->get('cache.key');

        // Always recalculate when using the raw option
        if (!$key || $raw) {

            // Generate the start of the key
            $key = $request->getScheme() . '_' . $request->getHost() . $request->getPath();
            $filterHeaders = array('Cache-Control');
            $filterQuery = array();

            // Check to see how and if the key should be filtered
            foreach (explode(';', $request->getParams()->get('cache.key_filter')) as $part) {
                $pieces = array_map('trim', explode('=', $part));
                if (isset($pieces[1])) {
                    $remove = array_map('trim', explode(',', $pieces[1]));
                    if ($pieces[0] == 'header') {
                        $filterHeaders = array_merge($filterHeaders, $remove);
                    } elseif ($pieces[0] == 'query') {
                        $filterQuery = array_merge($filterQuery, $remove);
                    }
                }
            }

            // Use the filtered query string
            $queryString = (string) $request->getQuery()->filter(function($key, $value) use ($filterQuery) {
                return !in_array($key, $filterQuery);
            });

            // Use the filtered headers
            $headerString = http_build_query($request->getHeaders()->map(function($key, $value) {
                return count($value) == 1 ? $value[0] : $value;
            })->filter(function($key, $value) use ($filterHeaders) {
                return !in_array($key, $filterHeaders);
            })->getAll());

            if ($raw) {
                $key = strtolower('gz_' . $key . $queryString . '_' . $headerString);
            } else {
                $key = strtolower('gz_' . md5($key . $queryString . '_' . $headerString));
                $request->getParams()->set('cache.key', $key);
            }
        }

        return $key;
    }

    /**
     * Check if a response in cache will satisfy the request before sending
     *
     * @param Event $event
     */
    public function onRequestBeforeSend(Event $event)
    {
        $request = $event['request'];
        // This request is being prepared
        $key = spl_object_hash($request);
        $hashKey = $this->getCacheKey($request);
        $this->cached[$key] = $hashKey;
        $cachedData = $this->getCacheAdapter()->fetch($hashKey);

        // If the cached data was found, then make the request into a
        // manually set request
        if ($cachedData) {

            if ($this->serialize) {
                $cachedData = unserialize($cachedData);
            }

            unset($this->cached[$key]);
            $response = new Response($cachedData['c'], $cachedData['h'], $cachedData['b']);
            $response->setHeader('Age', time() - strtotime($response->getDate() ?: 'now'));
            if (!$response->hasHeader('X-Guzzle-Cache')) {
                $response->setHeader('X-Guzzle-Cache', "key={$key}");
            }

            // Validate that the response satisfies the request
            if ($this->canResponseSatisfyRequest($request, $response)) {
                $request->setResponse($response);
            }
        }
    }

    /**
     * If possible, store a response in cache after sending
     *
     * @param Event $event
     */
    public function onRequestSent(Event $event)
    {
        $request = $event['request'];
        $response = $event['response'];
        if ($response->canCache()) {
            // The request is complete and now processing the response
            $key = spl_object_hash($request);
            if (isset($this->cached[$key])) {
                if ($response->isSuccessful()) {
                    if ($request->getParams()->get('cache.override_ttl')) {
                        $lifetime = $request->getParams()->get('cache.override_ttl');
                        $response->setHeader('X-Guzzle-Cache', "key={$key}, ttl={$lifetime}");
                    } else {
                        $lifetime = $response->getMaxAge();
                    }
                    $this->saveCache($this->cached[$key], $response, $lifetime);
                }
                // Remove the hashed placeholder from the parameters object
                unset($this->cached[$key]);
            }
        }
    }

    /**
     * Revalidate a cached response
     *
     * @param RequestInterface $request  Request to revalidate
     * @param Response         $response Response to revalidate
     *
     * @return bool
     */
    public function revalidate(RequestInterface $request, Response $response)
    {
        $revalidate = clone $request;
        $revalidate->getEventDispatcher()->removeSubscriber($this);
        $revalidate->removeHeader('Pragma')
                   ->removeHeader('Cache-Control')
                   ->setHeader('If-Modified-Since', $response->getDate());

        if ($response->getEtag()) {
            $revalidate->setHeader('If-None-Match', '"' . $response->getEtag() . '"');
        }

        try {

            $validateResponse = $revalidate->send();
            if ($validateResponse->getStatusCode() == 200) {
                // The server does not support validation, so use this response
                $request->setResponse($validateResponse);
                // Store this response in cache if possible
                if ($validateResponse->canCache()) {
                    $this->saveCache($this->getCacheKey($request), $validateResponse, $validateResponse->getMaxAge());
                }

                return false;
            }

            if ($validateResponse->getStatusCode() == 304) {
                // Make sure that this response has the same ETage
                if ($validateResponse->getEtag() != $response->getEtag()) {
                    return false;
                }
                // Replace cached headers with any of these headers from the
                // origin server that might be more up to date
                $modified = false;
                foreach (array('Date', 'Expires', 'Cache-Control', 'ETag', 'Last-Modified') as $name) {
                    if ($validateResponse->hasHeader($name)) {
                        $modified = true;
                        $response->setHeader($name, $validateResponse->getHeader($name));
                    }
                }
                // Store the updated response in cache
                if ($modified && $response->canCache()) {
                    $this->saveCache($this->getCacheKey($request), $response, $response->getMaxAge());
                }

                return true;
            }

        } catch (BadResponseException $e) {

            // 404 errors mean the resource no longer exists, so remove from
            // cache, and prevent an additional request by throwing the exception
            if ($e->getResponse()->getStatusCode() == 404) {
                $this->getCacheAdapter()->delete($this->getCacheKey($request));
                throw $e;
            }
        }

        // Other exceptions encountered in the revalidation request are ignored
        // in hopes that sending a request to the origin server will fix it
        return false;
    }

    /**
     * Check if a cache response satisfies a request's caching constraints
     *
     * @param RequestInterface $request  Request to validate
     * @param Response         $response Response to validate
     *
     * @return bool
     */
    public function canResponseSatisfyRequest(RequestInterface $request, Response $response)
    {
        $responseAge = $response->getAge();

        // Check the request's max-age header against the age of the response
        if ($request->hasCacheControlDirective('max-age') &&
            $responseAge > $request->getCacheControlDirective('max-age')) {
            return false;
        }

        // Check the response's max-age header
        if ($response->isFresh() === false) {
            $maxStale = $request->getCacheControlDirective('max-stale');
            if (null !== $maxStale) {
                if ($maxStale !== true && $response->getFreshness() < (-1 * $maxStale)) {
                    return false;
                }
            } elseif ($responseAge > $response->getCacheControlDirective('max-age')) {
                return false;
            }
        }

        // Only revalidate GET requests
        if ($request->getMethod() == RequestInterface::GET) {
            // Check if the response must be validated against the origin server
            if ($request->getHeader('Pragma') == 'no-cache' ||
                $request->hasCacheControlDirective('no-cache') ||
                $request->hasCacheControlDirective('must-revalidate') ||
                $response->hasCacheControlDirective('must-revalidate') ||
                $response->hasCacheControlDirective('no-cache')) {
                // no-cache: When no parameters are present, always revalidate
                // When parameters are present in no-cache and the request includes
                // those same parameters, then the response must re-validate
                // I'll need an example of what fields look like in order to
                // implement a smarter version of no-cache

                // Requests can decline to revalidate against the origin server
                // by setting the cache.revalidate param to one of:
                //      never  - To never revalidate and always contact the origin server
                //      skip   - To skip revalidation and just use what is in cache
                switch ($request->getParams()->get('cache.revalidate')) {
                    case 'never':
                        return false;
                    case 'skip':
                        return true;
                }

                return $this->revalidate($request, $response);
            }
        }

        return true;
    }

    /**
     * Save data to the cache adapter
     *
     * @param string   $key      The cache key
     * @param Response $response The response to cache
     * @param int      $lifetime Amount of seconds to cache
     *
     * @return int Returns the lifetime of the cached data
     */
    protected function saveCache($key, Response $response, $lifetime = null)
    {
        $lifetime = $lifetime ?: $this->defaultLifetime;

        // If the data is cacheable, then save it to the cache adapter
        if ($lifetime) {
            // Remove excluded headers from the response  (see RFC 2616:13.5.1)
            foreach ($this->excludeResponseHeaders as $header) {
                $response->removeHeader($header);
            }
            // Add a Date header to the response if none is set (for validation)
            if (!$response->getDate()) {
                $response->setHeader('Date', Utils::getHttpDate('now'));
            }
            $data = array(
                'c' => $response->getStatusCode(),
                'h' => $response->getHeaders(),
                'b' => $response->getBody(true)
            );
            if ($this->serialize) {
                $data = serialize($data);
            }
            $this->getCacheAdapter()->save($key, $data, $lifetime);
        }

        return $lifetime;
    }
}
