<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Model;

/**
 * Contains information about the logging status of a bucket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class BucketLoggingStatus
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
     * Get the XML data
     *
     * @return SimpleXMLElement
     */
    public function getXml()
    {
        return $this->xml;
    }

    /**
     * Checks if the bucket has logging enabled
     *
     * @return bool
     */
    public function isLoggingEnabled()
    {
        return $this->xml->LoggingEnabled != false;
    }

    /**
     * Specifies the bucket whose logging status is being returned. This
     * element specifies the bucket where server access logs will be delivered.
     *
     * @return string|bool
     */
    public function getTargetBucket()
    {
        return ($this->isLoggingEnabled())
            ? (string)$this->xml->LoggingEnabled->TargetBucket
            : false;
    }

    /**
     * Specifies the prefix for the keys that the log files are being stored
     * under.
     *
     * @return string|bool
     */
    public function getTargetPrefix()
    {
        return ($this->isLoggingEnabled()) 
            ? (string)$this->xml->LoggingEnabled->TargetPrefix
            : false;
    }

    /**
     * Get an array of grants for bucket logging
     *
     * The response array will be numerically indexed.  Each value of the
     * array will contain an inner array containing the following keys:
     * <ul>
     *      <li>[0] => email address</li>
     *      <li>[1] => permission</li>
     * </ul>
     *
     * @return array
     */
    public function getGrants()
    {
        $result = array();
        if ($this->isLoggingEnabled()) {
            foreach ($this->xml->LoggingEnabled->TargetGrants->Grant as $grant) {
                $result[] = array(
                    (string)$grant->Grantee->EmailAddress,
                    (string)$grant->Permission
                );
            }
        }

        return $result;
    }
}