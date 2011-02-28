<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Sqs\Command;

use Guzzle\Service\Aws\Sqs\Command\ReceiveMessage;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ReceiveMessageTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\Sqs\Command\ReceiveMessage
     */
    public function testReceiveMessage()
    {
        $command = new ReceiveMessage();
        $this->assertSame($command, $command->setQueueUrl('http://sqs.us-east-1.amazonaws.com/123456789012/testQueue'));
        $this->assertSame($command, $command->setMaxMessages(3));
        $this->assertSame($command, $command->setVisibilityTimeout(12));
        $this->assertSame($command, $command->addAttribute(ReceiveMessage::ALL));
        $this->assertSame($command, $command->addAttribute(ReceiveMessage::SENDER_ID));

        $client = $this->getServiceBuilder()->getClient('test.sqs');
        $this->setMockResponse($client, 'ReceiveMessageResponse');
        $client->execute($command);

        $request = (string) $command->getRequest();
        $response = (string) $command->getResponse();

        $this->assertEquals('GET', $command->getRequest()->getMethod());
        $this->assertContains('GET /123456789012/testQueue?Action=ReceiveMessage', $request);
        $this->assertContains('VisibilityTimeout=12', $request);
        $this->assertContains('MaxNumberOfMessages=3', $request);
        $this->assertContains('AttributeName.1=All', $request);
        $this->assertContains('AttributeName.2=SenderId', $request);
        $this->assertEquals('sqs.us-east-1.amazonaws.com', $command->getRequest()->getHost());
        $this->assertEquals('/123456789012/testQueue', $command->getRequest()->getPath());
        $this->assertEquals('b6633655-283d-45b4-aee4-4e84e0ae6afa', $command->getRequestId());

        $this->assertEquals(array(
            array(
                'message_id' => '5fea7756-0ea4-451a-a703-a558b933e274',
                'receipt_handle' => "MbZj6wDWli+JvwwJaBV+3dcjk2YW2vA3+STFFljTM8tJJg6HRG6PYSasuWXPJB+Cw\nLj1FjgXUv1uSj1gUPAWV66FU/WeR4mq2OKpEGYWbnLmpRCJVAyeMjeU5ZBdtcQ+QE\nauMZc8ZRv37sIW2iJKq3M9MFx1YvV11A2x/KSbkJ0=",
                'md5_of_body' => 'fafb00f5732ab283681e124bf8747ed1',
                'body' => 'This is a test message',
                'sender_id' => '195004372649',
                'sent_timestamp' => '1238099229000',
                'approximate_receive_count' => '5',
                'approximate_first_receive_timestamp' => '1250700979248'
            ),
            array(
                'message_id' => '5fea7756-0ea4-451a-a703-a558b933e275',
                'receipt_handle' => "MbZj6wDWli+JvwwJaBV+3dcjk2YW2vA3+STFFljTM8tJJg6HRG6PYSasuWXPJB+Cw\nLj1FjgXUv1uSj1gUPAWV66FU/WeR4mq2OKpEGYWbnLmpRCJVAyeMjeU5ZBdtcQ+QE\nauMZc8ZRv37sIW2iJKq3M9MFx1YvV11A2x/KSbkJ1=",
                'md5_of_body' => 'fafb00f5732ab283681e124bf8747ed1',
                'body' => 'This is a test message',
                'sender_id' => '195004372649',
                'sent_timestamp' => '1238099229001',
                'approximate_receive_count' => '3',
                'approximate_first_receive_timestamp' => '1250700979228'
            )
        ), $command->getResult());
    }
}