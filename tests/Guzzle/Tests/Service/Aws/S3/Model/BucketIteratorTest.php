<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

use Guzzle\Service\Aws\S3\Model\BucketIterator;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class BucketIteratorTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Model\BucketIterator
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testDecideMarker()
    {
        $client = $this->getServiceBuilder()->getClient('test.s3');

        $xml = new \SimpleXMLElement($this->getMockResponse($client, 'ListBucketResponse')->getBody(true));
        $iterator = BucketIterator::factory($client, $xml);
        $this->assertNull($iterator->decideMarker());

        $xml = new \SimpleXMLElement($this->getMockResponse($client, 'ListBucketTruncatedResponse')->getBody(true));
        $iterator = BucketIterator::factory($client, $xml);
        $this->assertEquals('Neo', $iterator->decideMarker());

        $xml = new \SimpleXMLElement($this->getMockResponse($client, 'ListBucketNextMarkerPrefixMarkerResponse')->getBody(true));
        $iterator = BucketIterator::factory($client, $xml);
        $this->assertEquals('Moe', $iterator->decideMarker());

        $xml = new \SimpleXMLElement($this->getMockResponse($client, 'ListBucketCommonPrefixUseCommonResponse')->getBody(true));
        $iterator = BucketIterator::factory($client, $xml);
        $this->assertEquals('photos/2006/January/', $iterator->decideMarker());
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Model\BucketIterator
     * @covers Guzzle\Service\ResourceIterator
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\ListBucket
     */
    public function testSendsSubequentCalls()
    {
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'ListBucketResponse');
        $xml = new \SimpleXMLElement($this->getMockResponse($client, 'ListBucketNextMarkerPrefixMarkerResponse')->getBody(true));
        $iterator = BucketIterator::factory($client, $xml);
        $results = $iterator->toArray();
        
        $this->assertEquals(array (
            array(
                'key' => 'Nelson',
                'last_modified' => '2006-01-01T12:00:00.000Z',
                'etag' => '828ef3fdfa96f00ad9f27c383fc9ac7f',
                'size' => 5,
                'storage_class' => 'STANDARD',
                'owner' => array(
                    'id' => 'bcaf161ca5fb16fd081034f',
                    'display_name' => 'webfile',
                ),
            ),
            array(
                'key' => 'Neo',
                'last_modified' => '2006-01-01T12:00:00.000Z',
                'etag' => '828ef3fdfa96f00ad9f27c383fc9ac7f',
                'size' => 4,
                'storage_class' => 'STANDARD',
                'owner' => array(
                    'id' => 'bcaf1ffd86a5fb16fd081034f',
                    'display_name' => 'webfile',
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
}