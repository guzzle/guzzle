<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Sqs\Command;

use Guzzle\Service\Aws\Sqs\Command\GetQueueAttributes;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetQueueAttributesTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\Sqs\Command\GetQueueAttributes
     */
    public function testGetQueueAttributes()
    {
        $command = new GetQueueAttributes();
        $this->assertSame($command, $command->setQueueUrl('http://sqs.us-east-1.amazonaws.com/123456789012/testQueue'));
        $this->assertSame($command, $command->addAttribute('All'));
        $this->assertSame($command, $command->addAttribute('VisibilityTimeout'));

        $client = $this->getServiceBuilder()->getClient('test.sqs');
        $this->setMockResponse($client, 'GetQueueAttributesResponse');
        $client->execute($command);

        $request = (string) $command->getRequest();
        $response = (string) $command->getResponse();

        $this->assertEquals('GET', $command->getRequest()->getMethod());
        $this->assertContains('GET /123456789012/testQueue?Action=GetQueueAttributes&AttributeName.1=All&AttributeName.2=VisibilityTimeout', $request);
        $this->assertEquals('sqs.us-east-1.amazonaws.com', $command->getRequest()->getHost());
        $this->assertEquals('/123456789012/testQueue', $command->getRequest()->getPath());
        $this->assertEquals('cbd6e5cb-b4f1-46fb-8356-3b4705290606', $command->getRequestId());

        $this->assertEquals(array(
            'approximate_number_of_messages' => 0,
            'approximate_number_of_messages_not_visible' => 1,
            'created_timestamp' => 1238098969,
            'last_modified_timestamp' => 1238099106,
            'visibility_timeout' => 32
        ), $command->getResult());
    }
}