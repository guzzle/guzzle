<?php

namespace Guzzle\Http\Plugin;

use Guzzle\Guzzle;
use Guzzle\Common\Cache\CacheAdapterInterface;
use Guzzle\Common\Event\Observer;
use Guzzle\Common\Event\Subject;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\EntityEnclosingRequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\Request;

/**
 * Plugin to enable the caching of GET and HEAD requests.  Caching can be done
 * on all requests passing through this plugin or only after retrieving
 * resources with cacheable response headers.  This is a simple implementation
 * of RFC 2616 and should be considered a private transparent proxy cache
 * (authorization and private data can be cached).
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CachePlugin implements Observer
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
     * @param CacheAdapterInterface $adapter Cache adapter to write and read cache data
     * @param bool $serialize (optional) Set to TRUE to serialize data before writing
     * @param int $defaultLifetime (optional) Set the default cache lifetime
     */
    public function __construct(CacheAdapterInterface $adapter, $serialize = false, $defaultLifetime = 3600)
    {
        $this->adapter = $adapter;
        $this->serialize = (bool) $serialize;
        $this->defaultLifetime = (int) $defaultLifetime;
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
     * @param string $raw Set to TRUE to retrieve the un-encoded string for debugging
     *
     * @return string
     */
    public function getCacheKey(RequestInterface $request, $raw = false)
    {
        // See if the key has already been calculated
        $key = $request->getParams()->get('cache.key');

        // Always recalculate when using the raw option
        if (!$key || $raw) {

            // Check to see if the key should be filtered
            $filter = $request->getParams()->get('cache.key_filter');
            // The generate the start of the key
            $key = $request->getScheme() . '&' . $request->getHost() . $request->getPath();
            $filterHeaders = array('Cache-Control');
            $filterQuery = array();

            if ($filter) {
                // Parse the filter string
                foreach (explode(';', $filter) as $part) {
                    $pieces = array_map('trim', explode('=', $part));
                    if (!isset($pieces[1])) {
                        continue;
                    }
                    $remove = array_map('trim', explode(',', $pieces[1]));
                    switch ($pieces[0]) {
                        case 'header':
                            $filterHeaders = array_merge($filterHeaders, $remove);
                            break;
                        case 'query':
                            $filterQuery = array_merge($filterQuery, $remove);
                            break;
                    }
                }
            }

            // Use the filtered query string
            $queryString = (string) $request->getQuery()->filter(function($key, $value) use ($filterQuery) {
                return !in_array($key, $filterQuery);
            });

            // Use the filtered headers
            $headerString = http_build_query($request->getHeaders()->filter(function($key, $value) use ($filterHeaders) {
                return !in_array($key, $filterHeaders);
            })->getAll());

            if ($raw) {
                $key = 'gzrq_' . $key . $queryString . '&' . $headerString;
            } else {
                $key = 'gzrq_' . md5($key . $queryString . '&' . $headerString);
                $request->getParams()->set('cache.key', $key);
            }
        }

        return $key;
    }

    /**
     * {@inheritdoc}
     *
     * @param RequestInterface $subject Request to process
     */
    public function update(Subject $subject, $event, $context = null)
    {
        // @codeCoverageIgnoreStart
        if (!($subject instanceof RequestInterface)) {
            return;
        }
        // @codeCoverageIgnoreEnd

        switch ($event) {
            case 'event.attach':
                // If the request is not cacheable, remove this observer
                if (!$subject->canCache()) {
                    $subject->getEventManager()->detach($this);
                }
                break;
            case 'request.before_send':
                // This request is being prepared
                $key = spl_object_hash($subject);
                $hashKey = $this->getCacheKey($subject);
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
                    $response->setHeader('X-Guzzle-Cache', $hashKey);

                    // Validate that the response satisfies the request
                    if ($this->canResponseSatisfyRequest($subject, $response)) {
                        $subject->setResponse($response);
                    }
                }
                break;
            case 'request.sent':
                $response = $subject->getResponse();
                if ($response->canCache()) {
                    // The request is complete and now processing the response
                    $response = $subject->getResponse();
                    $key = spl_object_hash($subject);
                    
                    if (isset($this->cached[$key]) && $response->isSuccessful()) {
                        if ($subject->getParams()->get('cache.override_ttl')) {
                            $lifetime = $subject->getParams()->get('cache.override_ttl');
                            $response->setHeader('X-Guzzle-Ttl', $lifetime);
                        } else {
                            $lifetime = $response->getMaxAge();
                        }
                        $this->saveCache($this->cached[$key], $response, $lifetime);
                    }
                    // Remove the hashed placeholder from the parameters object
                    unset($this->cached[$key]);
                }
                break;
        }
    }

    /**
     * Revalidate a cached response
     *
     * @param RequestInterface $request Request to revalidate
     * @param Response $response Response to revalidate
     *
     * @return bool
     */
    public function revalidate(RequestInterface $request, Response $response)
    {
        $revalidate = clone $request;
        $revalidate->getEventManager()->detach($this);
        $revalidate->setHeader('If-Modified-Since', $response->getDate());
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
                    $this->saveCache($this->getCacheKey($request), $validateResponse);
                }

                return false;
            } else if ($validateResponse->getStatusCode() == 304) {
                // Make sure that this response has the same ETage
                if ($validateResponse->getEtag() != $response->getEtag()) {
                    return false;
                } else {
                    // Replace cached headers with any of these headers from the
                    // origin server that might be more up to date
                    foreach (array('Date', 'Expires', 'Cache-Control', 'ETag', 'Last-Modified') as $name) {
                        if ($validateResponse->hasHeader($name)) {
                            $response->setHeader($name, $validateResponse->getHeader($name));
                        }
                    }
                    // Store the updated response in cache
                    if ($response->canCache()) {
                        $this->saveCache($this->getCacheKey($request), $response);
                    }

                    return true;
                }
            }
        } catch (\Exception $e) {
            // Don't fail on re-validation attempts
        }

        return false;
    }

    /**
     * Check if a cache response satisfies a request's caching constraints
     *
     * @param RequestInterface $request Request to validate
     * @param Response $response Response to validate
     *
     * @return bool
     */
    public function canResponseSatisfyRequest(RequestInterface $request, Response $response)
    {
        $maxAge = null;
        $responseAge = $response->getAge();

        // Check the request's max-age header against the age of the response
        if ($request->hasCacheControlDirective('max-age')) {
            if ($responseAge > $request->getCacheControlDirective('max-age')) {
                return false;
            }
        }

        // Check the response's max-age header
        if ($response->isFresh() === false) {
            $maxStale = $request->getCacheControlDirective('max-stale');
            if (null !== $maxStale) {
                if ($maxStale !== true && $response->getFreshness() < (-1 * $maxStale)) {
                    return false;
                }
            } else if ($responseAge > $response->getCacheControlDirective('max-age')) {
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
                //      accept  - To use what is in cache
                //      decline - To always get a new copy
                if ($request->getParams()->get('cache.revalidate')) {
                    return $request->getParams()->get('cache.revalidate') != 'decline';
                }

                return $this->revalidate($request, $response);
            }
        }

        return true;
    }

    /**
     * Save data to the cache adapter
     *
     * @param string $key The cache key
     * @param Response $response The response to cache
     * @param int $lifetime (optional) Amount of seconds to cache
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
                $response->setHeader('Date', Guzzle::getHttpDate('now'));
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