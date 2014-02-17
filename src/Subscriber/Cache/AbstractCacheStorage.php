<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Message\MessageInterface;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\StreamInterface;

abstract class AbstractCacheStorage implements CacheStorageInterface
{
    /** @var string */
    protected $keyPrefix;

    /** @var int Default cache TTL */
    protected $defaultTtl;

    public function cache(RequestInterface $request, ResponseInterface $response)
    {
        $currentTime = time();

        $ttl = 0;
        if ($cacheControl = $response->getHeader('Cache-Control')) {
            $stale = $cacheControl->getDirective('stale-if-error');
            $ttl += $stale == true ? $ttl : $stale;
            $ttl += $cacheControl->getDirective('max-age');
        }
        $ttl = $ttl ?: $this->defaultTtl;

        // Determine which manifest key should be used
        $key = $this->getCacheKey($request);
        $persistedRequest = $this->persistHeaders($request);
        $entries = array();

        if ($manifest = $this->getCache($key)) {
            // Determine which cache entries should still be in the cache
            $vary = (string) $response->getHeader('Vary');
            foreach (unserialize($manifest) as $entry) {
                // Check if the entry is expired
                if ($entry[4] < $currentTime) {
                    continue;
                }
                $entry[1]['vary'] = isset($entry[1]['vary']) ? $entry[1]['vary'] : '';
                if ($vary != $entry[1]['vary'] || !$this->requestsMatch($vary, $entry[0], $persistedRequest)) {
                    $entries[] = $entry;
                }
            }
        }

        // Persist the response body if needed
        $bodyDigest = null;
        if ($response->getBody() && $response->getBody()->getSize() > 0) {
            $bodyDigest = $this->getBodyKey($request->getUrl(), $response->getBody());
            $this->saveCache($bodyDigest, (string) $response->getBody(), $ttl);
        }

        array_unshift($entries, array(
            $persistedRequest,
            $this->persistHeaders($response),
            $response->getStatusCode(),
            $bodyDigest,
            $currentTime + $ttl
        ));

        $this->saveCache($key, serialize($entries));
    }

    public function delete(RequestInterface $request)
    {
        $key = $this->getCacheKey($request);
        if ($entries = $this->getCache($key)) {
            // Delete each cached body
            foreach (unserialize($entries) as $entry) {
                if ($entry[3]) {
                    $this->deleteCache($entry[3]);
                }
            }
            $this->deleteCache($key);
        }
    }

    public function purge($url)
    {
        foreach (array('GET', 'HEAD', 'POST', 'PUT', 'DELETE') as $method) {
            $this->delete(new Request($method, $url));
        }
    }

    public function fetch(RequestInterface $request)
    {
        $key = $this->getCacheKey($request);
        if (!($entries = $this->getCache($key))) {
            return null;
        }

        $match = null;
        $headers = $this->persistHeaders($request);
        $entries = unserialize($entries);
        foreach ($entries as $index => $entry) {
            if ($this->requestsMatch(isset($entry[1]['vary']) ? $entry[1]['vary'] : '', $headers, $entry[0])) {
                $match = $entry;
                break;
            }
        }

        if (!$match) {
            return null;
        }

        // Ensure that the response is not expired
        $response = null;
        if ($match[4] < time()) {
            $response = -1;
        } else {
            $response = new Response($match[2], $match[1]);
            if ($match[3]) {
                if ($body = $this->getCache($match[3])) {
                    $response->setBody($body);
                } else {
                    // The response is not valid because the body was somehow deleted
                    $response = -1;
                }
            }
        }

        if ($response === -1) {
            // Remove the entry from the metadata and update the cache
            unset($entries[$index]);
            if ($entries) {
                $this->saveCache($key, serialize($entries));
            } else {
                $this->deleteCache($key);
            }
            return null;
        }

        return $response;
    }

    /**
     * Hash a request URL into a string that returns cache metadata
     *
     * @param RequestInterface $request
     *
     * @return string
     */
    protected function getCacheKey(RequestInterface $request)
    {
        $url = $request->getUrl();

        return $this->keyPrefix . md5($request->getMethod() . ' ' . $url);
    }

    /**
     * Create a cache key for a response's body
     *
     * @param string          $url  URL of the entry
     * @param StreamInterface $body Response body
     *
     * @return string
     */
    protected function getBodyKey($url, StreamInterface $body)
    {
        return $this->keyPrefix . md5($url) . $body->getContentMd5();
    }

    abstract protected function getCache($key);
    abstract protected function saveCache($key, $value, $ttl = null);
    abstract protected function deleteCache($key);

    /**
     * Determines whether two Request HTTP header sets are non-varying
     *
     * @param string $vary Response vary header
     * @param array  $r1   HTTP header array
     * @param array  $r2   HTTP header array
     *
     * @return bool
     */
    private function requestsMatch($vary, $r1, $r2)
    {
        if ($vary) {
            foreach (explode(',', $vary) as $header) {
                $key = trim(strtolower($header));
                $v1 = isset($r1[$key]) ? $r1[$key] : null;
                $v2 = isset($r2[$key]) ? $r2[$key] : null;
                if ($v1 !== $v2) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Creates an array of cacheable and normalized message headers
     *
     * @param MessageInterface $message
     *
     * @return array
     */
    private function persistHeaders(MessageInterface $message)
    {
        // Headers are excluded from the caching (see RFC 2616:13.5.1)
        static $noCache = array(
            'age' => true,
            'connection' => true,
            'keep-alive' => true,
            'proxy-authenticate' => true,
            'proxy-authorization' => true,
            'te' => true,
            'trailers' => true,
            'transfer-encoding' => true,
            'upgrade' => true,
            'set-cookie' => true,
            'set-cookie2' => true
        );

        // Clone the response to not destroy any necessary headers when caching
        $headers = $message->getHeaders()->toArray();
        $headers = array_diff_key($headers, $noCache);
        // Cast the headers to a string
        $headers = array_map(function ($h) { return (string) $h; }, $headers);

        return $headers;
    }
}
