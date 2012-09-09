<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Exception\BadResponseException;

/**
 * Default revalidation strategy
 */
class DefaultRevalidation implements RevalidationInterface
{
    /**
     * @var CacheKeyProviderInterface Strategy used to create cache keys
     */
    protected $cacheKey;

    /**
     * @var CacheStorageInterface Cache object storing cache data
     */
    protected $storage;

    /**
     * @var CacheKeyProviderInterface $cacheKey Cache key strategy
     */
    public function __construct(CacheKeyProviderInterface $cacheKey, CacheStorageInterface $cache)
    {
        $this->cacheKey = $cacheKey;
        $this->adapter = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function revalidate(RequestInterface $request, Response $response)
    {
        static $replaceHeaders = array('Date', 'Expires', 'Cache-Control', 'ETag', 'Last-Modified');

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
                    $this->storage->cache(
                        $this->cacheKey->getCacheKey($request), $validateResponse, $validateResponse->getMaxAge()
                    );
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
                foreach ($replaceHeaders as $name) {
                    if ($validateResponse->hasHeader($name)) {
                        $modified = true;
                        $response->setHeader($name, $validateResponse->getHeader($name));
                    }
                }
                // Store the updated response in cache
                if ($modified && $response->canCache()) {
                    $this->storage->cache($this->cacheKey->getCacheKey($request), $response, $response->getMaxAge());
                }

                return true;
            }

        } catch (BadResponseException $e) {

            // 404 errors mean the resource no longer exists, so remove from
            // cache, and prevent an additional request by throwing the exception
            if ($e->getResponse()->getStatusCode() == 404) {
                $this->storage->delete($this->cacheKey->getCacheKey($request));
                throw $e;
            }
        }

        // Other exceptions encountered in the revalidation request are ignored
        // in hopes that sending a request to the origin server will fix it
        return false;
    }
}
