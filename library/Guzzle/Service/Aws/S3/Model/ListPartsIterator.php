<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Model;

use Guzzle\Service\ResourceIterator;
use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Command\Object\ListParts;
use Guzzle\Service\Aws\S3\S3Exception;

/**
 * Iterates over the multipart upload parts of an object by uploadId
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ListPartsIterator extends ResourceIterator
{
    /**
     * Factory method to create a new ListPartsIterator using the response of a
     * list parts request.
     *
     * @param S3Client $client The client responsible for sending subsquent requests
     * @param \SimpleXMLElement $result The initial list parts XML response
     * @param int $limit (optional) Total number of results to retrieve
     *
     * @return ListPartsIterator
     */
    public static function factory(S3Client $client, \SimpleXMLElement $result, $limit = -1)
    {
        $iterator = new self($client, array(
            'limit' => $limit,
            'page_size' => min(1000, $limit)
        ));

        $iterator->processResponse($result);

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
        $command = new ListParts(array(
            'xml_only' => true,
            'bucket' => $this->data['bucket'],
            'key' => $this->data['key']
        ));
        
        $command->setPartNumberMarker($this->nextToken)
            ->setUploadId($this->getUploadId())
            ->setLimit($this->limit)
            ->setMaxParts($this->calculatePageSize())
            ->setXmlResponseOnly(true)
            ->setClient($this->client);

        $command->execute();
        $this->processResponse($command->getResult());

        if (!$this->data['resources']) {
            // @codeCoverageIgnoreStart
            throw new S3Exception('Expected response for subsequent command');
            // @codeCoverageIgnoreEnd
        } else {
            $this->resourceList = $this->data['resources'];
            $this->nextToken = $this->data['next_token'];
            $this->retrievedCount += count($this->data['resources']);
            $this->currentIndex = 0;
        }
    }

    /**
     * Get the name of the containing bucket being iterated
     *
     * @return string
     */
    public function getBucketName()
    {
        return $this->data['bucket'];
    }

    /**
     * Get the key of the object being iterated
     *
     * @return string
     */
    public function getKey()
    {
        return $this->data['key'];
    }

    /**
     * Get the upload ID of the multipart upload
     *
     * @return string
     */
    public function getUploadId()
    {
        return $this->data['upload_id'];
    }

    /**
     * Get the initiator information of the multipart upload
     *
     * Returns an array containing the following keys:
     *      id => The initiator's ID
     *      display_name => The initiator's display name
     *
     * @return array
     */
    public function getInitiator()
    {
        return $this->data['initiator'];
    }

    /**
     * Get the owner of the object
     *
     * Returns an array containing the following keys:
     *      id => Owner ID
     *      display_name => Owner display name
     *
     * @return array
     */
    public function getOwner()
    {
        return $this->data['owner'];
    }

    /**
     * Get the storage class of the multipart upload
     *
     * @return string
     */
    public function getStorageClass()
    {
        return $this->data['storage_class'];
    }

    /**
     * Process a List Parts response
     *
     * @param \SimpleXMLElement $response The response to a list parts command
     */
    public function processResponse(\SimpleXMLElement $response)
    {
        $this->data = array(
            'bucket' =>  (string)$response->Bucket,
            'key' =>  (string)$response->Key,
            'upload_id' => (string)$response->UploadId,
            'part_number_marker' => (string)$response->PartNumberMarker,
            'next_token' => (string)$response->NextPartNumberMarker,
            'is_truncated' => (((string)$response->IsTruncated == 'true') ? true : false),
            'storage_class' => (string)$response->StorageClass,
            'owner' => array(
                'id' => (string)$response->Owner->ID,
                'display_name' => (string)$response->Owner->DisplayName,
            ),
            'initiator' => array(
                'id' => (string)$response->Initiator->ID,
                'display_name' => (string)$response->Initiator->DisplayName,
            )
        );

        $this->pageSize = (string)$response->MaxParts;

        if (!isset($this->data['resources'])) {
            $this->data['resources'] = array();
        }

        if ($response->Part) {
            foreach ($response->Part as $obj) {
                $this->data['resources'][] = $objData = array(
                    'part_number' => (string)$obj->PartNumber,
                    'last_modified' => (string)$obj->LastModified,
                    'etag' => str_replace('"', '', (string)$obj->ETag),
                    'size' => (int)$obj->Size
                );
            }
        }
    }
}