<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Model;

/**
 * Model to help organize a list of buckets.
 *
 * This object can be traversed and access as an array:
 *
 * <code>
 *     foreach ($bucketList as $bucket) {
 *         echo $bucket['name'] . ' ' . $bucket['creation_date'];
 *     }
 * </code>
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class BucketList implements \IteratorAggregate
{
    /**
     * @var SimpleXMLElement
     */
    protected $xml;

    /**
     * Constructor
     *
     * @param \SimpleXMLElement $xmlData XML data
     */
    public function __construct(\SimpleXMLElement $xmlData)
    {
        $this->xml = $xmlData;
    }

    /**
     * Get all buckets and data about each bucket
     *
     * @return array
     */
    public function getBuckets()
    {
        $ret = array();
        foreach ($this->xml->Buckets->Bucket as $ele) {
            $ret[(string)$ele->Name] = array(
                'name' => (string)$ele->Name,
                'creation_date' => (string)$ele->CreationDate
            );
        }
        
        return $ret;
    }

    /**
     * Get an array containing all bucket names
     *
     * @return array
     */
    public function getBucketNames()
    {
        $ret = array();
        foreach ($this->xml->Buckets->Bucket as $ele) {
            $ret[] = (string)$ele->Name;
        }
        
        return $ret;
    }

    /**
     * Returns an iteratable array containing the contents of getBuckets()
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->getBuckets());
    }

    /**
     * Get the bucket owner ID
     *
     * @return string
     */
    public function getOwnerId()
    {
        return (string)$this->xml->Owner->ID;
    }

    /**
     * Get the bucket owner display name
     *
     * @return string
     */
    public function getOwnerDisplayName()
    {
        return (string)$this->xml->Owner->DisplayName;
    }

    /**
     * Get the XML data
     *
     * @return SimpleXMLElement
     */
    public function getXml()
    {
        return $this->xml;
    }
}