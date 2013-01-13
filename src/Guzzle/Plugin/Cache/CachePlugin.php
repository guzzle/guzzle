<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Cache\CacheAdapterInterface;
use Guzzle\Common\Event;
use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Cache\DoctrineCacheAdapter;
use Doctrine\Common\Cache\ArrayCache;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Plugin to enable the caching of GET and HEAD requests.  Caching can be done on all requests passing through this
 * plugin or only after retrieving resources with cacheable response headers.
 *
 * This is a simple implementation of RFC 2616 and should be considered a private transparent proxy cache, meaning
 * authorization and private data can be cached.
 */
class CachePlugin implements EventSubscriberInterface
{
    /**
     * @var array SplObjectStorage of request cache keys to hold until a response is returned
     */
    private $cached;

    /**
     * @var CacheKeyProviderInterface Cache key provider
     */
    protected $keyProvider;

    /**
     * @var RevalidationInterface Cache revalidation strategy
     */
    protected $revalidation;

    /**
     * @var CanCacheStrategyInterface Object used to determine if a request can be cached
     */
    protected $canCache;

    /**
     * @var CacheStorageInterface $cache Object used to cache responses
     */
    protected $storage;

    /**
     * Construct a new CachePlugin. Cache options include the following:
     *
     * - CacheKeyProviderInterface key_provider:  (optional) Cache key provider
     * - CacheAdapterInterface     adapter:       (optional) Adapter used to cache objects. Pass this or a cache_storage
     * - CacheStorageInterface     storage:       (optional) Adapter used to cache responses
     * - RevalidationInterface     revalidation:  (optional) Cache revalidation strategy
     * - CanCacheInterface         can_cache:     (optional) Object used to determine if a request can be cached
     * - int                       default_ttl:   (optional) Default TTL to use when caching if no cache_storage was set
     *                                                       must set to 0 or it will assume the default of 3600 secs.
     *
     * @param array|CacheAdapterInterface|CacheStorageInterface $options Array of options for the cache plugin,
     *                                                                   cache adapter, or cache storage object.
     *
     * @throws InvalidArgumentException if no cache is provided and Doctrine cache is not installed
     */
    public function __construct($options = null)
    {
        if (!is_array($options)) {
            if ($options instanceof CacheAdapterInterface) {
                $options = array('adapter' => $options);
            } elseif ($options instanceof CacheStorageInterface) {
                $options = array('storage' => $options);
            } elseif (class_exists('Doctrine\Common\Cache\ArrayCache')) {
                $options = array('storage' => new DefaultCacheStorage(new DoctrineCacheAdapter(new ArrayCache()), 3600));
            } else {
                // @codeCoverageIgnoreStart
                throw new InvalidArgumentException('No cache was provided and Doctrine is not installed');
                // @codeCoverageIgnoreEnd
            }
        }

        // Add a cache storage if a cache adapter was provided
        if (!isset($options['adapter'])) {
            $this->storage = $options['storage'];
        } else {
            $this->storage = new DefaultCacheStorage(
                $options['adapter'],
                array_key_exists('default_ttl', $options) ? $options['default_ttl'] : 3600
            );
        }

        // Use the provided key provider or the default
        if (!isset($options['key_provider'])) {
            $this->keyProvider = new DefaultCacheKeyProvider();
        } else {
            if (is_callable($options['key_provider'])) {
                $this->keyProvider = new CallbackCacheKeyProvider($options['key_provider']);
            } else {
                $this->keyProvider = $options['key_provider'];
            }
        }

        if (!isset($options['can_cache'])) {
            $this->canCache = new DefaultCanCacheStrategy();
        } else {
            if (is_callable($options['can_cache'])) {
                $this->canCache = new CallbackCanCacheStrategy($options['can_cache']);
            } else {
                $this->canCache = $options['can_cache'];
            }
        }

        // Use the provided revalidation strategy or the default
        if (isset($options['revalidation'])) {
            $this->revalidation = $options['revalidation'];
        } else {
            $this->revalidation = new DefaultRevalidation($this->keyProvider, $this->storage, $this);
        }

        $this->cached = new \SplObjectStorage();
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
     * Check if a response in cache will satisfy the request before sending
     *
     * @param Event $event
     */
    public function onRequestBeforeSend(Event $event)
    {
        $request = $event['request'];
        if (!$this->canCache->canCacheRequest($request)) {
            return;
        }

        $hashKey = $this->keyProvider->getCacheKey($request);
        $this->cached[$request] = $hashKey;

        // If the cached data was found, then make the request into a
        // manually set request
        if ($cachedData = $this->storage->fetch($hashKey)) {
            unset($this->cached[$request]);
            $response = new Response($cachedData[0], $cachedData[1], $cachedData[2]);
            $response->setHeader('Age', time() - strtotime($response->getDate() ?: 'now'));
            if (!$response->hasHeader('X-Guzzle-Cache')) {
                $response->setHeader('X-Guzzle-Cache', "key={$hashKey}");
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

        if (isset($this->cached[$request])) {
            $cacheKey = $this->cached[$request];
            unset($this->cached[$request]);
            if ($this->canCache->canCacheResponse($response)) {
                $this->storage->cache(
                    $cacheKey,
                    $response,
                    $request->getParams()->get('cache.override_ttl') ?: $response->getMaxAge()
                );
            }
        }
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
            } elseif ($response->hasCacheControlDirective('max-age')
                && $responseAge > $response->getCacheControlDirective('max-age')
            ) {
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
                // When parameters are present in no-cache and the request includes those same parameters, then the
                // response must re-validate. I'll need an example of what fields look like in order to implement a
                // smarter version of no-cache

                // Requests can decline to revalidate against the origin server by setting the cache.revalidate param:
                // - never - To never revalidate and always contact the origin server
                // - skip  - To skip revalidation and just use what is in cache
                switch ($request->getParams()->get('cache.revalidate')) {
                    case 'never':
                        return false;
                    case 'skip':
                        return true;
                    default:
                        return $this->revalidation->revalidate($request, $response);
                }
            }
        }

        return true;
    }
}
