<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Cache\CacheAdapterInterface;
use Guzzle\Http\Utils;
use Guzzle\Http\Message\Response;

/**
 * Default cache storage implementation
 */
class DefaultCacheStorage implements CacheStorageInterface
{
    /**
     * @var CacheAdapterInterface Cache used to store cache data
     */
    protected $cache;

    /**
     * @var int Default cache TTL
     */
    protected $defaultTtl;

    /**
     * @var array Headers are excluded from the caching (see RFC 2616:13.5.1)
     */
    protected $excludeResponseHeaders = array(
        'Connection', 'Keep-Alive', 'Proxy-Authenticate', 'Proxy-Authorization',
        'TE', 'Trailers', 'Transfer-Encoding', 'Upgrade', 'Set-Cookie', 'Set-Cookie2'
    );

    /**
     * @param CacheAdapterInterface $cache      Cache used to store cache data
     * @param int                   $defaultTtl Default cache TTL
     */
    public function __construct(CacheAdapterInterface $cache, $defaultTtl = 0)
    {
        $this->cache = $cache;
        $this->defaultTtl = $defaultTtl;
    }

    /**
     * {@inheritdoc}
     */
    public function cache($key, Response $response, $ttl = null)
    {
        if ($ttl === null) {
            $ttl = $this->defaultTtl;
        }

        if ($ttl) {
            $response->setHeader('X-Guzzle-Cache', "key={$key}; ttl={$ttl}");
            // Remove excluded headers from the response  (see RFC 2616:13.5.1)
            foreach ($this->excludeResponseHeaders as $header) {
                $response->removeHeader($header);
            }
            // Add a Date header to the response if none is set (for validation)
            if (!$response->getDate()) {
                $response->setHeader('Date', Utils::getHttpDate('now'));
            }
            $this->cache->save(
                $key,
                array($response->getStatusCode(), $response->getHeaders()->getAll(), $response->getBody(true)),
                $ttl
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        $this->cache->delete($key);
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($key)
    {
        return $this->cache->fetch($key);
    }
}
