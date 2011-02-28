<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Common\Collection;
use Guzzle\Http\Message\Request;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;
use Guzzle\Guzzle;

/**
 * Abstract class to simplify HEAD object and GET object commands
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractRequestObject extends AbstractS3BucketCommand
{
    /**
     * Set the key of the object
     *
     * @param string $key The key or name of the object
     *
     * @return GetObject
     */
    public function setKey($key)
    {
        return $this->set('key', $key);
    }

    /**
     * Downloads the specified range of an object.
     *
     * @param string $range
     * 
     * @return GetObject
     */
    public function setRange($range)
    {
        return $this->set('range', $range);
    }

    /**
     * Return the object only if it has been modified since the specified time,
     * otherwise return a 304 (not modified).
     *
     * @param string $since
     * 
     * @return GetObject
     */
    public function setIfModifiedSince($since)
    {
        return $this->set('if_modified_since', Guzzle::getHttpDate($since));
    }

    /**
     * Return the object only if it has not been modified since the specified
     * time, otherwise return a 412 (precondition failed).
     *
     * @param string $since
     * 
     * @return GetObject
     */
    public function setIfUnmodifiedSince($since)
    {
        return $this->set('if_unmodified_since', Guzzle::getHttpDate($since));
    }

    /**
     * Return the object only if its entity tag (ETag) is the same as the one
     * specified, otherwise return a 412 (precondition failed).
     *
     * @param string $etag
     * 
     * @return GetObject
     */
    public function setIfMatch($etag)
    {
        return $this->set('if_match', $etag);
    }

    /**
     * Return the object only if its entity tag (ETag) is different from the
     * one specified, otherwise return a 304 (not modified).
     *
     * @param string $etag
     * 
     * @return GetObject
     */
    public function setIfNoneMatch($etag)
    {
        return $this->set('if_none_match', $etag);
    }

    /**
     * By default, the GET operation returns the latest version of an object.
     * To return a different version, use the versionId  sub-resource.
     *
     * @param string $versionId The version of the object to retrieve
     *
     * @return GetObject
     */
    public function setVersionId($versionId)
    {
        return $this->set('version_id', $versionId);
    }

    /**
     * Apply any set default object parameters to a request
     *
     * @param RequestInterface $request Request object to apply to
     */
    protected function applyDefaults(RequestInterface $request)
    {
        if ($this->hasKey('range')) {
            $request->setHeader('Range', $this->get('range'));
            $request->getCurlOptions()->set(CURLOPT_RANGE, $this->get('range'));
        }

        if ($this->hasKey('if_modified_since')) {
            $request->setHeader('If-Modified-Since', $this->get('if_modified_since'));
        }

        if ($this->hasKey('if_unmodified_since')) {
            $request->setHeader('If-Unmodified-Since', $this->get('if_unmodified_since'));
        }

        if ($this->hasKey('if_match')) {
            $request->setHeader('If-Match', $this->get('if_match'));
        }

        if ($this->hasKey('if_none_match')) {
            $request->setHeader('If-None-Match', $this->get('if_none_match'));
        }

        if ($this->hasKey('version_id')) {
            $request->getQuery()->add('versionId', $this->get('version_id'));
        }
    }
}