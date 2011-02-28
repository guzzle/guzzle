<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Bucket;

use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Service\Aws\S3\Command\Bucket\PutBucketNotification;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutBucketNotificationTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketNotification
     */
    public function testPutNotification()
    {
        $notification =
        '<NotificationConfiguration>' .
            '<TopicConfiguration>' . 
                '<Topic>arn:aws:sns:us-east-1:123456789012:myTopic</Topic>' .
                '<Event>s3:ReducedRedundancyLostObject</Event>' . 
            '</TopicConfiguration>' . 
        '</NotificationConfiguration>';

        $xml = new \SimpleXMLElement($notification);

        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\PutBucketNotification();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setNotification($xml));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'PutBucketNotificationResponse');
        $client->execute($command);

        $request = (string)$command->getRequest();
        $this->assertContains('PUT /?notification HTTP/1.1', $request);
        $this->assertContains('Host: test.s3.amazonaws.com', $request);
        $this->assertContains($notification, $request);
        
        $this->assertTrue($command->getResponse()->hasHeader('x-amz-sns-test-message-id'));
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Bucket\PutBucketNotification
     */
    public function testPutNotificationOff()
    {
        $notification = '<NotificationConfiguration />';

        $command = new \Guzzle\Service\Aws\S3\Command\Bucket\PutBucketNotification();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setNotification($notification));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'PutBucketNotificationOffResponse');
        $client->execute($command);

        $request = (string)$command->getRequest();
        $this->assertContains('PUT /?notification HTTP/1.1', $request);
        $this->assertContains('Host: test.s3.amazonaws.com', $request);
        $this->assertContains($notification, $request);

        $this->assertFalse($command->getResponse()->hasHeader('x-amz-sns-test-message-id'));
    }
}