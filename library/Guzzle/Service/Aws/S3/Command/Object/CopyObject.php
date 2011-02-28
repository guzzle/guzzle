<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Http\QueryString;
use Guzzle\Http\Message\Request;
use Guzzle\Guzzle;

/**
 * Copy an object from one location to another
 *
 * @guzzle key doc="Destination object key" required="true"
 * @guzzle bucket doc="Destination bucket" required="true"
 * @guzzle headers doc="Headers to set on the request" type="class:Guzzle\Common\Collection"
 * @guzzle acl doc="Canned ACL to set on the copied object"
 * @guzzle storage_class doc="Use STANDARD or REDUCED_REDUNDANCY storage"
 * @guzzle copy_source doc="The bucket and key of the source object in the form of /bucket/key" required="true"
 * @guzzle copy_source_if_match doc="Copies the object if its entity tag (ETag) matches the specified tag"
 * @guzzle copy_source_if_none_match doc="Copies the object if its entity tag (ETag) is different than the specified ETag"
 * @guzzle copy_source_if_modified_since doc="Copies the object if it has been modified since the specified time"
 * @guzzle copy_source_if_unmodified_since doce="Copies the object if it hasn't been modified since the specified time"
 *
 * @link http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTObjectCOPY.html
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CopyObject extends AbstractRequestObject
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::PUT, $this->get('bucket'), $this->get('key'));
        $this->applyDefaults($this->request);

        // Set the PUT copy specific headers
        
        $this->request->setHeader('x-amz-copy-source', QueryString::rawurlencode($this->get('copy_source'), array('/')));

        if ($this->get('metadata_directive')) {
            $this->request->setHeader('x-amz-metadata-directive', strtoupper($this->get('metadata_directive')));
        }

        if ($this->get('copy_source_if_match')) {
            $this->setRequestHeader('x-amz-copy-source-if-match', $this->get('copy_source_if_match'));
        }

        if ($this->get('copy_source_if_none_match')) {
            $this->request->setHeader('x-amz-copy-source-if-none-match', $this->get('copy_source_if_none_match'));
        }

        if ($this->get('copy_source_if_unmodified_since')) {
            $this->request->setHeader('x-amz-copy-source-if-unmodified-since', $this->get('copy_source_if_unmodified_since'));
        }

        if ($this->get('copy_source_if_modified_since')) {
            $this->request->setHeader('x-amz-copy-source-if-modified-since', $this->get('copy_source_if_modified_since'));
        }

        $this->request->getCurlOptions()->set(CURLOPT_LOW_SPEED_TIME, null);
    }

    /**
     * Set the canned ACL to apply to the object
     *
     * @param string $acl The ACL to set: private | public-read | public-read-write |
     *      authenticated-read | bucket-owner-read | bucket-owner-full-control
     *
     * @return CopyObject
     */
    public function setAcl($acl)
    {
        return $this->set('acl', $acl);
    }

    /**
     * Set the storage class of the object
     *
     * @param string $storageClass RRS enables customers to reduce their costs
     *      by storing non-critical, reproducible data at lower levels of
     *      redundancy than Amazon S3's standard storage.  One of
     *      STANDARD | REDUCED_REDUNDANCY
     *
     * @return CopyObject
     *
     * @throws InvalidArgumentException If the storage class is not a valid option
     */
    public function setStorageClass($storageClass)
    {
        $storageClass = strtoupper($storageClass);

        if ($storageClass != 'STANDARD' && $storageClass != 'REDUCED_REDUNDANCY') {
            throw new \InvalidArgumentException('$storageClass must be one of STANDARD or REDUCED_REDUNDANCY');
        }

        return $this->set('storage_class', $storageClass);
    }

    /**
     * Set the source object to copy from
     *
     * @param string $bucket Name of the bucket containing the source object
     * @param string $key Key of the source object
     *
     * @return CopyObject
     * @throws \InvalidArgumentException
     */
    public function setCopySource($bucket, $key)
    {
        return $this->set('copy_source', '/' . $bucket . '/' . $key);
    }
    
    /**
     * Specifies whether the metadata is copied from the source object or 
     * replaced with metadata provided in the request. If copied, the metadata, 
     * except for the version ID, remains unchanged. Otherwise, all original 
     * metadata is replaced by the metadata you specify. 
     *
     * @param string $directive The directive to set:  COPY | REPLACE
     * 
     * @return CopyObject
     *
     * @throws InvalidArgumentException if $directive is not COPY or REPLACE
     */
    public function setMetadataDirective($directive)
    {
        $directive = strtoupper($directive);

        if ($directive != 'COPY' && $directive != 'REPLACE') {
            throw new \InvalidArgumentException('$directive must be one of COPY or REPLACE');
        }

        return $this->set('metadata_directive', $directive);
    }

    /**
     * Copies the object if its entity tag (ETag) matches the specified tag;
     * otherwise, the request returns a 412 HTTP status code error
     * (precondition failed).
     *
     * @param string $etag The ETag to use
     *
     * @return CopyObject
     */
    public function setCopySourceIfMatch($etag)
    {
        return $this->set('copy_source_if_match', $etag);
    }

    /**
     * Copies the object if its entity tag (ETag) is different than the
     * specified ETag; otherwise, the request returns a 412 HTTP status code
     * error (failed condition).
     *
     * @param string $etag The ETag to use
     *
     * @return CopyObject
     */
    public function setCopySourceIfNoneMatch($etag)
    {
        return $this->set('copy_source_if_none_match', $etag);
    }

    /**
     * Copies the object if it hasn't been modified since the specified time;
     * otherwise, the request returns a 412 HTTP status code error
     * (precondition failed).
     *
     * @param string $date The HTTP date to use
     *
     * @return CopyObject
     */
    public function setCopySourceIfUnmodifiedSince($date)
    {
        return $this->set('copy_source_if_unmodified_since', Guzzle::getHttpDate($date));
    }

    /**
     * Copies the object if it has been modified since the specified time;
     * otherwise, the request returns a 412 HTTP status code error (failed
     * condition).
     *
     * @param string $date The HTTP date to use
     *
     * @return CopyObject
     */
    public function setCopySourceIfModifiedSince($date)
    {
        return $this->set('copy_source_if_modified_since', Guzzle::getHttpDate($date));
    }
}