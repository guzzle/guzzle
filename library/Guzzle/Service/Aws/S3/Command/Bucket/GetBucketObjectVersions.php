<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Command\Bucket;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Model\VersionBucketIterator;
use Guzzle\Service\Aws\S3\Command\AbstractS3BucketCommand;

/**
 * List metadata about all of the versions of objects in a bucket. You can also
 * use request parameters as selection criteria to return metadata about a
 * subset of all the object versions.
 *
 * @link http://docs.amazonwebservices.com/AmazonS3/latest/API/index.html?RESTBucketGETVersion.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle bucket required="true"
 * @guzzle delimiter doc="A delimiter is a character that you specify to group keys"
 * @guzzle marker doc="Specifies the key in the bucket that you want to start listing from. Also, see version-id-marker."
 * @guzzle prefix doc=""
 * @guzzle max_keys doc="The number of keys to return per request"
 * @guzzle version_id_marker doc="Specifies the object version you want to start listing from. Also, see key-marker."
 */
class GetBucketObjectVersions extends AbstractS3BucketCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getS3Request(RequestInterface::GET, $this->get('bucket'));

        $queryString = $this->request->getQuery();
        $queryString->set('versions', false);

        if ($this->get('delimiter')) {
            $queryString->set('delimiter', $this->get('delimiter'));
        }

        if ($this->get('key_marker')) {
            $queryString->set('key-marker', $this->get('key_marker'));
        }

        if ($this->get('max_keys')) {
            $queryString->set('max-keys', $this->get('max_keys'));
        }

        if ($this->get('prefix')) {
            $queryString->set('prefix', $this->get('prefix'));
        }

        if ($this->get('version_id_marker')) {
            $this->request->getQuery()->set('version-id-marker', $this->get('version_id_marker'));
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $this->result = new \SimpleXMLElement($this->getResponse()->getBody(true));
    }

    /**
     * Returns the SimpleXMLElement representation of the response body
     *
     * @return SimpleXMLElement
     */
    public function getResult()
    {
        return parent::getResult();
    }

    /**
     * Set the object version you want to start listing from.
     *
     * @param string $versionIdMarker Valid version ID
     *
     * @return GetBucketObjectVersions
     */
    public function setVersionIdMarker($versionIdMarker)
    {
        return $this->set('version_id_marker', $versionIdMarker);
    }

    /**
     * Set the delimiter parameter.
     *
     * A delimiter is a character you use to group keys.
     * 
     * @param string $delimiter The delimiter
     *
     * @return GetBucketObjectVersions
     */
    public function setDelimiter($delimiter)
    {
        return $this->set('delimiter', $delimiter);
    }

    /**
     * Specify the key to start with when listing objects in a bucket.
     *
     * @param string $keyMarker The key to start with
     *
     * @return GetBucketObjectVersions
     */
    public function setKeyMarker($keyMarker)
    {
        return $this->set('key_marker', $keyMarker);
    }

    /**
     * Sets the maximum number of keys returned in the response body.
     *
     * @param string $maxKeys The maximum number of keys to return in the response
     *
     * @return GetBucketObjectVersions
     */
    public function setMaxKeys($maxKeys)
    {
        return $this->set('max_keys', $maxKeys);
    }

    /**
     * Set the prefix parameter
     * 
     * @param string $prefix Prefix that must be present in each key of the response
     *
     * @return GetBucketObjectVersions
     */
    public function setPrefix($prefix)
    {
        return $this->set('prefix', $prefix);
    }
}