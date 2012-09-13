<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\RequestInterface;

/**
 * Default cache key strategy that uses all but the body of a request for the key. Add a cache.key_filter parameter to
 * a request to modify what gets cached.
 *
 * cache.key_filter is a string containing semicolon separated values. Each value contains a comma separated list of
 * things to filter from the key. The currently supported filters are 'header' and 'query'. For example, to filter out
 * the 'X-Foo' header, the 'X-Bar' header, and the 'test' query string value, set the filter to
 * "header=X-Foo,X-Bar; query=test"
 */
class DefaultCacheKeyProvider implements CacheKeyProviderInterface
{
    /**
     * @var string Request parameter holding the cache key
     */
    const CACHE_KEY = 'cache.key';

    /**
     * @var string Request parameter holding the cache key filter settings
     */
    const CACHE_KEY_FILTER = 'cache.key_filter';

    /**
     * @var string Request parameter holding the raw key
     */
    const CACHE_KEY_RAW = 'cache.raw_key';

    /**
     * {@inheritdoc}
     */
    public function getCacheKey(RequestInterface $request)
    {
        // See if the key has already been calculated
        $key = $request->getParams()->get(self::CACHE_KEY);

        if (!$key) {

            $cloned = clone $request;
            $cloned->removeHeader('Cache-Control');

            // Check to see how and if the key should be filtered
            foreach (explode(';', $request->getParams()->get(self::CACHE_KEY_FILTER)) as $part) {
                $pieces = array_map('trim', explode('=', $part));
                if (isset($pieces[1])) {
                    foreach (array_map('trim', explode(',', $pieces[1])) as $remove) {
                        if ($pieces[0] == 'header') {
                            $cloned->removeHeader($remove);
                        } elseif ($pieces[0] == 'query') {
                            $cloned->getQuery()->remove($remove);
                        }
                    }
                }
            }

            $raw = (string) $cloned;
            $key = 'GZ' . md5($raw);
            $request->getParams()->set(self::CACHE_KEY, $key)->set(self::CACHE_KEY_RAW, $raw);
        }

        return $key;
    }
}
