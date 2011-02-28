<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Unfuddle\Command;

/**
 * @group Unfuddle
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MessageTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Unfuddle\Command\Messages\GetMessage
     * @covers Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand
     */
    public function testGetMessage()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new \Guzzle\Service\Unfuddle\Command\Messages\GetMessage();
        $command->setProjectId(1);
        $this->setMockResponse($client, 'message.get_messages');
        $client->execute($command);
        $this->assertContains('unfuddle.com/api/v1/projects/1/messages', $command->getRequest()->getUrl());

        $command = new \Guzzle\Service\Unfuddle\Command\Messages\GetMessage();
        $command->setProjectId(1)->setId(1);
        $this->setMockResponse($client, 'message.get_messages');
        $client->execute($command);
        $this->assertContains('unfuddle.com/api/v1/projects/1/messages/1', $command->getRequest()->getUrl());
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\Messages\UpdateMessage
     * @covers Guzzle\Service\Unfuddle\Command\Messages\AbstractMessageBodyCommand
     * @covers Guzzle\Service\Unfuddle\Command\AbstractUnfuddleBodyCommand
     * @covers Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand
     */
    public function testUpdateMessage()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new \Guzzle\Service\Unfuddle\Command\Messages\UpdateMessage();
        $command->setProjectId(1);
        $command->setId(1);
        $command->setBody('Testing part deux')
                ->setTitle('Testing')
                ->setCategories(array(1));

        // Make sure the same property can be set more than once
        $command->setTitle('Testing - Reloaded');

        // We don't care about the response to this command, so just use this mock
        $this->setMockResponse($client, 'message.get_messages');
        $client->execute($command);
        $this->assertContains('PUT /api/v1/projects/1/messages/1 HTTP/1.1', (string)$command->getRequest());
        $this->assertEquals('<?xml version="1.0"?>' . "\n" . '<message><body>Testing part deux</body><title>Testing - Reloaded</title><categories><category id="1"></category></categories></message>', trim((string)$command->getRequest()->getBody()));
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\Messages\CreateMessage
     * @covers Guzzle\Service\Unfuddle\Command\Messages\AbstractMessageBodyCommand
     * @covers Guzzle\Service\Unfuddle\Command\AbstractUnfuddleBodyCommand
     * @covers Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand
     */
    public function testCreateMessage()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new \Guzzle\Service\Unfuddle\Command\Messages\CreateMessage();
        $this->assertEquals($command, $command->setTitle('Test create'));
        $this->assertEquals($command, $command->setBody('body'));
        $this->setMockResponse($client, 'message.create_message');
        $client->execute($command);
        $message = (string)$command->getRequest();
        $this->assertContains('POST /api/v1/messages HTTP/1.1', $message);
        $this->assertContains('<message><title>Test create</title><body>body</body></message>', $message);
        $this->assertContains('Content-Type: application/xml', $message);
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\Messages\DeleteMessage
     * @covers Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand
     */
    public function testDeleteMessage()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new \Guzzle\Service\Unfuddle\Command\Messages\DeleteMessage();
        $this->assertEquals($command, $command->setId(1));
        // We don't care about the response, so just set anything
        $this->setMockResponse($client, 'message.create_message');
        $client->execute($command);
        $message = (string)$command->getRequest();
        $this->assertContains('DELETE /api/v1/messages/1 HTTP/1.1', $message);
    }
}