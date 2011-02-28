<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Sqs\Command;

use Guzzle\Service\Aws\Sqs\Command\SendMessage;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SendMessageTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\Sqs\Command\SendMessage
     */
    public function testDeleteMessage()
    {
        $command = new SendMessage();
        $this->assertSame($command, $command->setQueueUrl('http://sqs.us-east-1.amazonaws.com/123456789012/testQueue'));
        $this->assertSame($command, $command->setMessage('This is a test message'));

        $client = $this->getServiceBuilder()->getClient('test.sqs');
        $this->setMockResponse($client, 'SendMessageResponse');
        $client->execute($command);

        $request = (string) $command->getRequest();
        $response = (string) $command->getResponse();

        $this->assertEquals('GET', $command->getRequest()->getMethod());
        $this->assertContains('GET /123456789012/testQueue?Action=SendMessage&Message=This%20is%20a%20test%20message', $request);
        $this->assertEquals('sqs.us-east-1.amazonaws.com', $command->getRequest()->getHost());
        $this->assertEquals('/123456789012/testQueue', $command->getRequest()->getPath());
        $this->assertEquals('27daac76-34dd-47df-bd01-1f6e873584a0', $command->getRequestId());
        
        $this->assertEquals(array(
            'message_id' => '5fea7756-0ea4-451a-a703-a558b933e274',
            'md5' => 'fafb00f5732ab283681e124bf8747ed1'
        ), $command->getResult());
    }
}