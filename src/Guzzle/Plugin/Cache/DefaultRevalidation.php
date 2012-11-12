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
     * @var CachePlugin
     */
    protected $plugin;

    /**
     * @param CacheKeyProviderInterface $cacheKey Cache key strategy
     * @param CacheStorageInterface     $storage  Cache storage
     * @param CachePlugin               $plugin   Cache plugin to remove from revalidation requests
     */
    public function __construct(CacheKeyProviderInterface $cacheKey, CacheStorageInterface $cache, CachePlugin $plugin)
    {
        $this->cacheKey = $cacheKey;
        $this->storage = $cache;
        $this->plugin = $plugin;
    }

    /**
     * {@inheritdoc}
     */
    public function revalidate(RequestInterface $request, Response $response)
    {
        try {
            $revalidate = $this->createRevalidationRequest($request, $response);
            $validateResponse = $revalidate->send();
            if ($validateResponse->getStatusCode() == 200) {
                return $this->handle200Response($request, $validateResponse);
            } elseif ($validateResponse->getStatusCode() == 304) {
                return $this->handle304Response($request, $validateResponse, $response);
            }
        } catch (BadResponseException $e) {
            $this->handleBadResponse($e);
        }

        // Other exceptions encountered in the revalidation request are ignored
        // in hopes that sending a request to the origin server will fix it
        return false;
    }

    /**
     * Handles a bad response when attempting to revalidate
     *
     * @param BadResponseException $e Exception encountered
     *
     * @throws BadResponseException
     */
    protected function handleBadResponse(BadResponseException $e)
    {
        // 404 errors mean the resource no longer exists, so remove from
        // cache, and prevent an additional request by throwing the exception
        if ($e->getResponse()->getStatusCode() == 404) {
            $this->storage->delete($this->cacheKey->getCacheKey($e->getRequest()));
            throw $e;
        }
    }

    /**
     * Creates a request to use for revalidation
     *
     * @param RequestInterface $request  Request
     * @param Response         $response Response to revalidate
     *
     * @return RequestInterface returns a revalidation request
     */
    protected function createRevalidationRequest(RequestInterface $request, Response $response)
    {
        $revalidate = clone $request;
        $revalidate->removeHeader('Pragma')
            ->removeHeader('Cache-Control')
            ->setHeader('If-Modified-Since', $response->getDate());

        if ($response->getEtag()) {
            $revalidate->setHeader('If-None-Match', '"' . $response->getEtag() . '"');
        }

        // Remove any cache plugins that might be on the request
        $revalidate->getEventDispatcher()->removeSubscriber($this->plugin);

        return $revalidate;
    }

    /**
     * Handles a 200 response response from revalidating. The server does not support validation, so use this response.
     *
     * @param RequestInterface $request          Request that was sent
     * @param Response         $validateResponse Response received
     *
     * @return bool Returns true if valid, false if invalid
     */
    protected function handle200Response(RequestInterface $request, Response $validateResponse)
    {
        $request->setResponse($validateResponse);
        // Store this response in cache if possible
        if ($validateResponse->canCache()) {
            $this->storage->cache(
                $this->cacheKey->getCacheKey($request), $validateResponse, $validateResponse->getMaxAge()
            );
        }

        return false;
    }

    /**
     * Handle a 304 response and ensure that it is still valid
     *
     * @param RequestInterface $request          Request that was sent
     * @param Response         $validateResponse Response received
     * @param Response         $response         Original cached response
     *
     * @return bool Returns true if valid, false if invalid
     */
    protected function handle304Response(RequestInterface $request, Response $validateResponse, Response $response)
    {
        static $replaceHeaders = array('Date', 'Expires', 'Cache-Control', 'ETag', 'Last-Modified');

        // Make sure that this response has the same ETag
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
}
