<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Sqs\Command;

use Guzzle\Service\Aws\Sqs\Command\SetQueueAttributes;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SetQueueAttributesTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\Sqs\Command\SetQueueAttributes
     */
    public function testSetQueueAttributes()
    {
        $command = new SetQueueAttributes();
        $this->assertSame($command, $command->setQueueUrl('http://sqs.us-east-1.amazonaws.com/123456789012/testQueue'));
        $this->assertSame($command, $command->addAttribute(SetQueueAttributes::VISIBILITY_TIMEOUT, 12));
        $this->assertSame($command, $command->addAttribute(SetQueueAttributes::MAXIMUM_MESSAGE_SIZE, 8192));

        $client = $this->getServiceBuilder()->getClient('test.sqs');
        $this->setMockResponse($client, 'SetQueueAttributesResponse');
        $client->execute($command);

        $request = (string) $command->getRequest();
        $response = (string) $command->getResponse();

        $this->assertEquals('GET', $command->getRequest()->getMethod());
        $this->assertContains('GET /123456789012/testQueue?Action=SetQueueAttributes', $request);

        $this->assertEquals('VisibilityTimeout', $command->getRequest()->getQuery()->get('Attribute.1.Name'));
        $this->assertEquals('12', $command->getRequest()->getQuery()->get('Attribute.1.Value'));
        $this->assertEquals('MaximumMessageSize', $command->getRequest()->getQuery()->get('Attribute.2.Name'));
        $this->assertEquals('8192', $command->getRequest()->getQuery()->get('Attribute.2.Value'));

        $this->assertEquals('sqs.us-east-1.amazonaws.com', $command->getRequest()->getHost());
        $this->assertEquals('/123456789012/testQueue', $command->getRequest()->getPath());
        $this->assertEquals('e5cca473-4fc0-4198-a451-8abb94d02c75', $command->getRequestId());
        $this->assertInstanceOf('SimpleXMLElement', $command->getResult());
    }
}