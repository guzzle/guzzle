<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Sqs\Command;

use Guzzle\Service\Aws\Sqs\Command\DeleteMessage;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DeleteMessageTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\Sqs\Command\DeleteMessage
     */
    public function testDeleteMessage()
    {
        $command = new DeleteMessage();
        $this->assertSame($command, $command->setQueueUrl('http://sqs.us-east-1.amazonaws.com/123456789012/testQueue'));
        $this->assertSame($command, $command->setReceiptHandle('MbZj6wDWli%2BJvwwJaBV%2B3dcjk2YW2vA3%2BSTFFljTM8tJJg6HRG6PYSasuWXPJB%2BCwLj1FjgXUv1uSj1gUPAWV66FU/WeR4mq2OKpEGYWbnLmpRCJVAyeMjeU5ZBdtcQ%2BQEauMZc8ZRv37sIW2iJKq3M9MFx1YvV11A2x/KSbkJ0='));

        $client = $this->getServiceBuilder()->getClient('test.sqs');
        $this->setMockResponse($client, 'DeleteMessageResponse');
        $client->execute($command);

        $request = (string) $command->getRequest();
        $response = (string) $command->getResponse();
        
        $this->assertEquals('GET', $command->getRequest()->getMethod());
        $this->assertContains('GET /123456789012/testQueue?Action=DeleteMessage', $request);
        $this->assertContains('ReceiptHandle=MbZj6wDWli%252BJvwwJaBV%252B3dcjk2YW2vA3%252BSTFFljTM8tJJg6HRG6PYSasuWXPJB%252BCwLj1FjgXUv1uSj1gUPAWV66FU%2FWeR4mq2OKpEGYWbnLmpRCJVAyeMjeU5ZBdtcQ%252BQEauMZc8ZRv37sIW2iJKq3M9MFx1YvV11A2x%2FKSbkJ0%3D', $request);
        $this->assertEquals('sqs.us-east-1.amazonaws.com', $command->getRequest()->getHost());
        $this->assertEquals('/123456789012/testQueue', $command->getRequest()->getPath());
        $this->assertEquals('b5293cb5-d306-4a17-9048-b263635abe42', $command->getRequestId());
        $this->assertInstanceOf('SimpleXMLElement', $command->getResult());
    }
}