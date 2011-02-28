<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

use Guzzle\Service\Aws\S3\Command\Bucket\GetBucketNotification;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetBucketNotificationTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\GetBucketNotification
     */
    public function testGetBucketNotification()
    {
        $command = new GetBucketNotification();
        $this->assertSame($command, $command->setBucket('test'));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'GetBucketNotificationResponse');
        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/?notification', $command->getRequest()->getUrl());
        $this->assertEquals('GET', $command->getRequest()->getMethod());
        $this->assertInstanceOf('SimpleXMLElement', $command->getResult());

        $notification = $command->getResult();
        $this->assertEquals('arn:aws:sns:us-east-1:123456789012:myTopic', (string)$notification->TopicConfiguration->Topic);
        $this->assertEquals('s3:ReducedRedundancyLostObject', (string)$notification->TopicConfiguration->Event);
    }
}