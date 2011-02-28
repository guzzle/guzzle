<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Sqs\Command;

use Guzzle\Service\Aws\Sqs\Command\ChangeMessageVisibility;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ChangeMessageVisibilityTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\Sqs\Command\ChangeMessageVisibility
     */
    public function testChangeMessageVisibility()
    {
        $command = new ChangeMessageVisibility();
        $this->assertSame($command, $command->setQueueUrl('http://sqs.us-east-1.amazonaws.com/123456789012/testQueue'));
        $this->assertSame($command, $command->setReceiptHandle('MbZj6wDWli%2BJvwwJaBV%2B3dcjk2YW2vA3%2BSTFFljTM8tJJg6HRG6PYSasuWXPJB%2BCwLj1FjgXUv1uSj1gUPAWV66FU/WeR4mq2OKpEGYWbnLmpRCJVAyeMjeU5ZBdtcQ%2BQEauMZc8ZRv37sIW2iJKq3M9MFx1YvV11A2x/KSbkJ0='));
        $this->assertSame($command, $command->setVisibilityTimeout(12));

        $client = $this->getServiceBuilder()->getClient('test.sqs');
        $this->setMockResponse($client, 'ChangeMessageVisibilityResponse');
        $client->execute($command);

        $request = (string) $command->getRequest();
        $response = (string) $command->getResponse();

        $this->assertEquals('GET', $command->getRequest()->getMethod());
        $this->assertContains('GET /123456789012/testQueue?Action=ChangeMessageVisibility', $request);
        $this->assertContains('ReceiptHandle=MbZj6wDWli%252BJvwwJaBV%252B3dcjk2YW2vA3%252BSTFFljTM8tJJg6HRG6PYSasuWXPJB%252BCwLj1FjgXUv1uSj1gUPAWV66FU%2FWeR4mq2OKpEGYWbnLmpRCJVAyeMjeU5ZBdtcQ%252BQEauMZc8ZRv37sIW2iJKq3M9MFx1YvV11A2x%2FKSbkJ0%3D', $request);
        $this->assertContains('VisibilityTimeout=12', $request);
        $this->assertEquals('sqs.us-east-1.amazonaws.com', $command->getRequest()->getHost());
        $this->assertEquals('/123456789012/testQueue', $command->getRequest()->getPath());
        $this->assertEquals('6a7a282a-d013-4a59-aba9-335b0fa48bed', $command->getRequestId());
        $this->assertInstanceOf('SimpleXMLElement', $command->getResult());
    }
}