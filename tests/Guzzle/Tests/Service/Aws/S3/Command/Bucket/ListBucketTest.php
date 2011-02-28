<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ListBucketTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\ListBucket
     * @covers Guzzle\Service\Aws\S3\Model\BucketIterator
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testListBucket()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\ListBucket();
        $command->setBucket('bucket');
        $command->setLimit(100);
        $this->assertEquals(100, $command->get('limit'));
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'ListBucketResponse');
        $client->execute($command);
        
        $list = $command->getResult();

        $this->assertEquals('bucket', $list->getBucketName());
        $this->assertEquals(array(), $list->getCommonPrefixes());
        $results = $list->toArray();

        $this->assertEquals(array(
            array(
                'key' => 'my-image.jpg',
                'last_modified' => '2009-10-12T17:50:30.000Z',
                'etag' => 'fba9dede5f27731c9771645a39863328',
                'size' => 434234,
                'storage_class' => 'STANDARD',
                'owner' => array(
                    'id' => '8a6925ce4a7f21c32aa379004fef',
                    'display_name' => 'mtd@amazon.com',
                ),
            ),
            array(
                'key' => 'my-third-image.jpg',
                'last_modified' => '2009-10-12T17:50:30.000Z',
                'etag' => '1b2cf535f27731c974343645a3985328',
                'size' => 64994,
                'storage_class' => 'STANDARD',
                'owner' => array(
                    'id' => '8a69b1ddee97f21c32aa379004fef',
                    'display_name' => 'mtd@amazon.com',
                ),
            ),
        ), $results);
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\ListBucket
     * @covers Guzzle\Service\Aws\S3\Model\BucketIterator
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testListBucketExhaustive()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\ListBucket();
        $command->setBucket('johnsmith');
        $command->setLimit(20);
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, array('ListBucketCommonPrefixUseCommonResponse', 'ListBucketResponse'));
        $client->execute($command);

        $list = $command->getResult();

        $this->assertEquals('johnsmith', $list->getBucketName());
        $this->assertEquals(array('photos/2006/January/'), $list->getCommonPrefixes());
        $results = $list->toArray();

        $this->assertEquals(array(
            array(
                'key' => 'photos/2005/December/test.html',
                'last_modified' => '2009-01-01T12:00:00.000Z',
                'etag' => 'ce1acdafcc879d7eee54cf4e97334078',
                'size' => 1234,
                'storage_class' => 'STANDARD',
                'owner' => array(
                    'id' => '214153b66967d86f031c7249d1d9a80249109428335cd08f1cdc487b4566cb1b',
                    'display_name' => 'John Smith',
                ),
            ),
            array(
                'key' => 'my-image.jpg',
                'last_modified' => '2009-10-12T17:50:30.000Z',
                'etag' => 'fba9dede5f27731c9771645a39863328',
                'size' => 434234,
                'storage_class' => 'STANDARD',
                'owner' => array(
                    'id' => '8a6925ce4a7f21c32aa379004fef',
                    'display_name' => 'mtd@amazon.com',
                ),
            ),
            array (
                'key' => 'my-third-image.jpg',
                'last_modified' => '2009-10-12T17:50:30.000Z',
                'etag' => '1b2cf535f27731c974343645a3985328',
                'size' => 64994,
                'storage_class' => 'STANDARD',
                'owner' => array(
                    'id' => '8a69b1ddee97f21c32aa379004fef',
                    'display_name' => 'mtd@amazon.com',
                ),
            ),
        ), $results);
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\ListBucket
     * @covers Guzzle\Service\Aws\S3\Model\BucketIterator
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testListBucketExhaustiveWithLimit()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\ListBucket();
        $command->setBucket('johnsmith')
                ->setLimit(3);

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, array('ListBucketNextMarkerPrefixMarkerResponse', 'ListBucketResponse'));
        $client->execute($command);
        $all = $command->getResult()->toArray();

        $requests = $this->getMockedRequests();
        $this->assertEquals(2, count($requests));
        $this->assertEquals(3, $requests[0]->getQuery()->get('max-keys'));
        $this->assertEquals(1, $requests[1]->getQuery()->get('max-keys'));
    }
}