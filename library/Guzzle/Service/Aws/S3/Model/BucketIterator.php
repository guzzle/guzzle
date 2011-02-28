<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Model;

use Guzzle\Service\ResourceIterator;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Command\Bucket\ListBucket;
use Guzzle\Service\Aws\S3\S3Exception;

/**
 * Iterates over the keys in a bucket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class BucketIterator extends ResourceIterator
{
    /**
     * @var array Array of common prefixes.
     */
    protected $commonPrefixes = array();

    /**
     * Factory method to create a new BucketIterator using the response of a
     * listBucket request.
     *
     * @param S3Client $client The client responsible for sending subsquent requests
     * @param \SimpleXMLElement $bucketResult The initial XML response from a
     *      list bucket command
     * @param int $limit (optional) Total number of results to retrieve
     *
     * @return BucketIterator
     */
    public static function factory(S3Client $client, \SimpleXMLElement $bucketResult, $limit = -1)
    {
        $iterator = new self($client, array(
            'limit' => $limit,
            'page_size' => min(1000, $limit)
        ));

        $iterator->processListBucket($bucketResult);

        return $iterator;
    }

    /**
     * Send a request to retrieve the next page of results.
     *
     * @return void
     */
    protected function sendRequest()
    {
        // Issue a listBucket request and prevent
        $command = new ListBucket();
        $command->setBucket($this->data['bucket'])
            ->setPrefix($this->data['prefix'])
            ->setMarker($this->nextToken)
            ->setLimit($this->limit)
            ->setMaxKeys($this->calculatePageSize())
            ->setDelimiter($this->data['delimiter'])
            ->setXmlResponseOnly(true)
            ->setClient($this->client);

        $command->execute();
        $this->processListBucket($command->getResult());

        // var_export($this->data);
        
        if (!$this->data['resources']) {
            // @codeCoverageIgnoreStart
            throw new S3Exception('Expected response for subsequent list bucket command');
            // @codeCoverageIgnoreEnd
        } else {
            $this->resourceList = $this->data['resources'];
            $this->nextToken = $this->data['next_token'];
            $this->retrievedCount += count($this->data['resources']);
            $this->currentIndex = 0;
        }
    }

    /**
     * Get the name of the bucket being iterated
     *
     * @return string
     */
    public function getBucketName()
    {
        return $this->data['bucket'];
    }

    /**
     * Get any common prefixes.
     *
     * @return array Returns an array of common prefixes.
     */
    public function getCommonPrefixes()
    {
        return $this->data['common_prefixes'];
    }

    /**
     * Decide what the last marker of a ListBucket response is.
     *
     * @return string|null
     */
    public function decideMarker()
    {
        if ($this->data['is_truncated'] == false) {
            return null;
        }

        if ($this->data['next_marker']) {
            return $this->data['next_marker'];
        }

        // Get the last Key in the response
        $lastKey = (count($this->data['resources'])) ? $this->data['resources'][count($this->data['resources']) - 1]['key'] : null;
        // See if there was a CommonPrefixes passed back, and if so, get the last one
        $lastPrefix = (count($this->data['common_prefixes'])) ? $this->data['common_prefixes'][count($this->data['common_prefixes']) - 1] : null;

        return (strcmp((string)$lastKey, (string)$lastPrefix) > 0) ? $lastKey : $lastPrefix;
    }

    /**
     * Process a ListBucket response
     *
     * @param \SimpleXMLElement $response The response to a list bucket command
     */
    public function processListBucket(\SimpleXMLElement $response)
    {
        $this->data = array(
            'bucket' =>  (string)$response->Name,
            'name' =>  (string)$response->Name,
            'prefix' => (string)$response->Prefix,
            'marker' => (string)$response->Marker,
            'next_marker' => (string)$response->NextMarker,
            'is_truncated' => (((string)$response->IsTruncated == 'true') ? true : false),
            'common_prefixes' => array(),
            'next_token' => null,
            'page_size' => (string)$response->MaxKeys,
            'max_keys' => (string)$response->MaxKeys,
            'delimiter' => (string)$response->Delimiter
        );

        if (!isset($this->data['resources'])) {
            $this->data['resources'] = array();
        }

        if ($response->Contents) {
            foreach ($response->Contents as $obj) {
                $this->data['resources'][] = $objData = array(
                    'key' => (string)$obj->Key,
                    'last_modified' => (string)$obj->LastModified,
                    'etag' => str_replace('"', '', (string)$obj->ETag),
                    'size' => (int)$obj->Size,
                    'storage_class' => (string)$obj->StorageClass,
                    'owner' => array(
                        'id' => (string)$obj->Owner->ID,
                        'display_name' => (string)$obj->Owner->DisplayName
                    )
                );
            }
        }

        // If common prefixes are found, then be sure to store them
        if ($response->CommonPrefixes) {
            foreach ($response->CommonPrefixes as $prefix) {
                $this->data['common_prefixes'][] = (string)$prefix->Prefix;
            }
        }

        // if either keys were found or common prefixes were found
        if (count($this->data['resources']) || count($this->data['common_prefixes'])) {
            $this->data['next_token'] = $this->decideMarker($response);
        }
    }
}