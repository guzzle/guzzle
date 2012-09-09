<?php

namespace Guzzle\Plugin\Cache;

use Guzzle\Http\Message\RequestInterface;

/**
 * Default cache key strategy that uses all but the body of a request for the
 * key. Add a cache.key_filter parameter to a request to modify what gets cached.
 *
 * cache.key_filter is a string containing semicolon separated values. Each
 * value contains a comma separated list of things to filter from the key.
 * The currently supported filters are header and query. For example, to
 * filter out the X-Foo header, the X-Bar header, and the test query string
 * value, set the filter to "header=X-Foo,X-Bar; query=test"
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
     * {@inheritdoc}
     */
    public function getCacheKey(RequestInterface $request)
    {
        // See if the key has already been calculated
        $key = $request->getParams()->get(self::CACHE_KEY);

        if (!$key) {

            // Generate the start of the key
            $key = $request->getMethod()
                . '_' . $request->getScheme()
                . '_' . $request->getHost()
                . $request->getPath();

            $filterHeaders = array('Cache-Control');
            $filterQuery = array();

            // Check to see how and if the key should be filtered
            foreach (explode(';', $request->getParams()->get(self::CACHE_KEY_FILTER)) as $part) {
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

            $key = strtolower('gz_' . md5("{$key}{$queryString}_{$headerString}"));
            $request->getParams()->set(self::CACHE_KEY, $key);
        }

        return $key;
    }
}
