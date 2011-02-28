<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Model\BucketIterator;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * List the contents of a bucket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true"
 * @guzzle delimiter
 * @guzzle marker
 * @guzzle prefix
 * @guzzle max_keys doc="The number of keys to return per request"
 * @guzzle limit doc="The maximum number of objects to return"
 */
class ListBucket extends AbstractS3BucketCommand
{
    const MAX_PER_REQUEST = 1000;

    /**
     * @var string The name of the bucket iterator class
     */
    protected $bucketIterator = 'Guzzle\Service\Aws\S3\Model\BucketIterator';

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::GET, $this->get('bucket'));

        $queryString = $this->request->getQuery();

        if ($this->get('delimiter')) {
            $queryString->set('delimiter', $this->get('delimiter'));
        }

        if ($this->get('marker')) {
            $queryString->set('marker', $this->get('marker'));
        }

        // Set the max-keys on the request (max_keys max is 1000)
        if ($this->get('max_keys')) {
            $queryString->set('max-keys', $this->get('max_keys'));
        }

        if ($this->get('prefix')) {
            $queryString->set('prefix', $this->get('prefix'));
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $xml = new \SimpleXMLElement($this->getResponse()->getBody(true));
        if ($this->get('xml_only')) {
             $this->result = $xml;
        } else {
            $className = $this->bucketIterator;
            $this->result = call_user_func(array($className, 'factory'), $this->client, $xml, $this->get('limit', -1));
        }
    }

    /**
     * Returns a BucketIterator object
     *
     * @return BucketIterator
     */
    public function getResult()
    {
        return parent::getResult();
    }

    /**
     * Set the delimiter parameter.
     *
     * A delimiter is a character you use to group keys. All keys that contain
     * the same string between the prefix and the first occurrence of the
     * delimiter are grouped under a single result element, CommonPrefixes.
     * These keys are not returned elsewhere in the response.
     *
     * @param string $delimiter The delimiter
     *
     * @return ListBucket
     */
    public function setDelimiter($delimiter)
    {
        return $this->set('delimiter', $delimiter);
    }

    /**
     * Specify the key to start with when listing objects in a bucket.
     *
     * Amazon S3 lists objects in alphabetical order.
     *
     * @param string $keyMarker The key to start with
     *
     * @return ListBucket
     */
    public function setMarker($keyMarker)
    {
        return $this->set('marker', $keyMarker);
    }

    /**
     * Set the maximum number of objects to retrieve from the bucket when
     * iterating over results.  When the xml_only parameter is set, this
     * parameter supercedes max_keys.
     *
     * @param integer $limit Maximum numbuer of objects to retrieve with the iterator
     *
     * @return ListBucket
     */
    public function setLimit($limit)
    {
        $this->set('limit', max(0, $limit));
        if ($limit < self::MAX_PER_REQUEST) {
            $this->setMaxKeys($limit);
        }

        return $this;
    }

    /**
     * Sets the maximum number of keys returned in the response body. The
     * response might contain fewer keys but will never contain more. If there
     * are additional keys that satisfy the search criteria but were not
     * returned because max-keys  was exceeded, the response contains
     * <isTruncated>true</isTruncated>. To return the additional keys, see
     * key-marker.
     *
     * @param string $maxKeys The maximum number of keys to return in the response
     *
     * @return ListBucket
     */
    public function setMaxKeys($maxKeys)
    {
        return $this->set('max_keys', $maxKeys);
    }

    /**
     * Set the prefix parameter
     *
     * Limits the response to keys that begin with the specified prefix. You
     * can use prefixes to separate a bucket into different groupings of keys.
     *
     * (You can think of using prefix to make groups in the same way you'd use
     * a folder in a file system.)
     *
     * @param string $prefix Prefix that must be present in each key of the response
     *
     * @return ListBucket
     */
    public function setPrefix($prefix)
    {
        return $this->set('prefix', $prefix);
    }

    /**
     * Set to TRUE to format the response only as XML rather than create a new
     * BucketIterator
     *
     * @param bool $xmlResponseOnly
     * 
     * @return ListBucket
     */
    public function setXmlResponseOnly($xmlResponseOnly)
    {
        return $this->set('xml_only', $xmlResponseOnly);
    }
}