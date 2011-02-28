<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Object;

use Guzzle\Service\Aws\S3\Command\Object\ListParts;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ListPartsTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\ListParts
     * @covers Guzzle\Service\Aws\S3\Model\ListPartsIterator
     */
    public function testListParts()
    {
        $command = new ListParts();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setKey('key'));
        $this->assertSame($command, $command->setUploadId('XXBsb2FkIElEIGZvciBlbHZpbmcncyVcdS1tb3ZpZS5tMnRzEEEwbG9hZA'));
        $this->assertSame($command, $command->setKey('XXBsb2FkIElEIGZvciBlbHZpbmcncyVcdS1tb3ZpZS5tMnRzEEEwbG9hZA'));
        $this->assertSame($command, $command->setMaxParts(2));
        $this->assertSame($command, $command->setLimit(100));
        $this->assertEquals(100, $command->get('limit'));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'ListPartsResponse');
        $client->execute($command);

        $parts = $command->getResult();

        $this->assertEquals('test', $parts->getBucketName());
        $this->assertEquals('key', $parts->getKey());
        $this->assertEquals('XXBsb2FkIElEIGZvciBlbHZpbmcncyVcdS1tb3ZpZS5tMnRzEEEwbG9hZA', $parts->getUploadId());

        $this->assertEquals(array(
            'id' => 'arn:aws:iam::11111111111:user/some-user-11116a31-17b5-4fb7-9df5-b288870f11xx',
            'display_name' => 'umat-user-11116a31-17b5-4fb7-9df5-b288870f11xx'
        ), $parts->getInitiator());

        $this->assertEquals(array(
            'id' => 'x1x16700c70b0b05597d7ecd6a3f92be',
            'display_name' => 'someName'
        ), $parts->getOwner());

        $this->assertEquals('STANDARD', $parts->getStorageClass());

        $results = $parts->toArray();

        $this->assertEquals(array(
            array(
                'part_number' => '3',
                'last_modified' => '2010-11-10T20:48:32.000Z',
                'etag' => '7778aef83f66abc1fa1e8477f296d394',
                'size' => 10485760
            ),
            array(
                'part_number' => '4',
                'last_modified' => '2010-11-10T20:48:33.000Z',
                'etag' => 'aaaa18db4cc2f85cedef654fccc4a4x8',
                'size' => 10485760
            ),
        ), $results);
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\ListParts
     * @covers Guzzle\Service\Aws\S3\Model\ListPartsIterator
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testListPartsExhaustive()
    {
        $command = new ListParts();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setKey('key'));
        $this->assertSame($command, $command->setUploadId('XXBsb2FkIElEIGZvciBlbHZpbmcncyVcdS1tb3ZpZS5tMnRzEEEwbG9hZA'));
        $this->assertSame($command, $command->setMaxParts(2));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, array('ListPartsTruncatedResponse', 'ListPartsResponse'));
        $client->execute($command);

        $parts = $command->getResult();
        
        $results = $parts->toArray();

        $this->assertEquals(array(
            array(
                'part_number' => '1',
                'last_modified' => '2010-11-10T20:48:30.000Z',
                'etag' => '7778aef83f66abc1fa1e8477f296d394',
                'size' => 10485760
            ),
            array(
                'part_number' => '2',
                'last_modified' => '2010-11-10T20:48:31.000Z',
                'etag' => 'aaaa18db4cc2f85cedef654fccc4a4x8',
                'size' => 10485760
            ),
            array(
                'part_number' => '3',
                'last_modified' => '2010-11-10T20:48:32.000Z',
                'etag' => '7778aef83f66abc1fa1e8477f296d394',
                'size' => 10485760
            ),
            array(
                'part_number' => '4',
                'last_modified' => '2010-11-10T20:48:33.000Z',
                'etag' => 'aaaa18db4cc2f85cedef654fccc4a4x8',
                'size' => 10485760
            ),
        ), $results);

        $requests = $this->getMockedRequests();
        $this->assertEquals(2, count($requests));
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\ListParts
     * @covers Guzzle\Service\Aws\S3\Model\ListPartsIterator
     * @covers Guzzle\Service\ResourceIterator
     */
    public function testListPartsExhaustiveWithLimit()
    {
        $command = new ListParts();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setKey('key'));
        $this->assertSame($command, $command->setUploadId('XXBsb2FkIElEIGZvciBlbHZpbmcncyVcdS1tb3ZpZS5tMnRzEEEwbG9hZA'));
        $this->assertSame($command, $command->setLimit(3));
        $this->assertEquals(3, $command->get('limit'));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, array('ListPartsTruncatedResponse', 'ListPartsResponse'));
        $client->execute($command);
        
        $parts = $command->getResult();
        $results = $parts->toArray();

        $requests = $this->getMockedRequests();
        $this->assertEquals(2, count($requests));
        $this->assertEquals(3, $requests[0]->getQuery()->get('max-parts'));
        $this->assertEquals(1, $requests[1]->getQuery()->get('max-parts'));
    }
}