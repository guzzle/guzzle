<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3;

use Guzzle\Service\Aws\Signature\SignatureV1;

/**
 * Amazon S3 Signature object
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class S3Signature extends SignatureV1
{
    /**
     * @var array Array of sub-resources
     */
    protected $subResources = array(
        'acl',
        'location',
        'logging',
        'notification',
        'partNumber',
        'policy',
        'requestPayment',
        'torrent',
        'uploadId',
        'uploads',
        'versionId',
        'versioning',
        'versions'
    );

    /**
     * Create a canonicalized AmzHeaders string for a signature.
     *
     * @param array $headers Associative array of request headers.
     *
     * @return string Returns canonicalized AMZ headers.
     */
    public function createCanonicalizedAmzHeaders(array $headers)
    {
        // @codeCoverageIgnoreStart
        if (empty($headers)) {
            return '';
        }
        // @codeCoverageIgnoreEnd
        
        $amzHeaders = array();
        $headers = array_change_key_case($headers, CASE_LOWER);
        $keys = array_keys($headers);
        $values = array_values($headers);
        
        foreach ($keys as $i => $k) {
            if (stripos($k, 'x-amz-') !== false) {
                $amzHeaders[$k] = trim($values[$i]);
            }
        }
        
        if (!$amzHeaders) {
            return '';
        } else {
            $canonicalized = '';
            ksort($amzHeaders);
            foreach ($amzHeaders as $k => $v) {
                $val = (is_array($v)) ? implode(',', $v) : $v;
                $canonicalized .= $k . ':' . $val . "\n";
            }
            return $canonicalized;
        }
    }

    /**
     * Create a canonicalized Resource string for a signature.
     *
     * @param array $headers Associative array of request headers.
     * @param string $path Path part of the URL.
     *
     * @return string Returns a canonicalized resource string.
     */
    public function createCanonicalizedResource(array $headers, $path)
    {
        $subResource = '';
        $parts = parse_url($path);
        $path = $parts['path'];
        $needsEndingSlash = false;

        if (!empty($headers)) {
            $headers = array_change_key_case($headers, CASE_LOWER);
            if ($headers['host']) {
                $host = str_replace(array('.s3.amazonaws.com', 's3.amazonaws.com'), '', $headers['host']);
                if ($host) {
                    if (preg_match('/^[A-Za-z0-9._\-]+$/', $host)) {
                        $bucket = $host . '/';
                        if ($path && $path[0] == '/') {
                            $path = substr($path, 1);
                        }
                    } else {
                        $bucket = parse_url($host, PHP_URL_HOST);
                    }
                    $path = '/' . $bucket . $path;
                }
            }
        }
        
        // Add an ending slash to the bucket if it was omitted
        if (preg_match('/^\/[A-Za-z0-9._\-]+$/', $path)) {
            $path .= '/';
        }
        
        // Add the sub resource if a valid sub resource is present
        if (array_key_exists('query', $parts)) {

            $q = array();
            parse_str($parts['query'], $q);
            $subs = array();

            foreach ($q as $key => $value) {
                if (in_array($key, $this->subResources)) {
                    $subs[$key] = $value;
                }
            }

            if (count($subs)) {
                $subResource .= '?';
                ksort($subs);
                $first = true;
                foreach ($subs as $key => $value) {
                    if (!$first) {
                        $subResource .= '&';
                    }
                    $subResource .= $key;
                    if ($value) {
                        $subResource .= '=' . $value;
                    }
                    $first = false;
                }
                $needsEndingSlash = false;
            }
        }

        $result = (($path) ? $path : '/') . $subResource;
        // @codeCoverageIgnoreStart
        if ($needsEndingSlash) {
            $result .= '/';
        }
        // @codeCoverageIgnoreEnd
        
        return $result;
    }

    /**
     * Create a canonicalized string for a signature.
     *
     * @param array $headers Associative array of request headers.
     * @param string $path Path part of the request URL.
     * @param string $httpVerb HTTP verb of the request (e.g GET, PUT, HEAD, etc...).
     *
     * @return string Returns a canonicalized string for an Amazon S3 signature.
     */
    public function createCanonicalizedString(array $headers, $path = '/', $httpVerb = 'GET')
    {
        $canonicalizedAmzHeaders = $this->createCanonicalizedAmzHeaders($headers);
        $canonicalizedResource = $this->createCanonicalizedResource($headers, $path);
        if (!empty($headers)) {
            $headers = array_change_key_case($headers, CASE_LOWER);
        }
        $contentType = (array_key_exists('content-type', $headers)) ? $headers['content-type'] : '';
        $contentMd5 = (array_key_exists('content-md5', $headers)) ? $headers['content-md5'] : '';
        if (!array_key_exists('x-amz-date', $headers) || !isset($headers['x-amz-date'])) {
            $date = (isset($headers['date'])) ? $headers['date'] : gmdate('r');
        } else {
            $date = '';
        }

        return "{$httpVerb}\n{$contentMd5}\n{$contentType}\n{$date}\n{$canonicalizedAmzHeaders}{$canonicalizedResource}";
    }
}