<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Unfuddle\Command;

use Guzzle\Service\Unfuddle\Command\Tickets\CreateTicket;
use Guzzle\Service\Unfuddle\Command\Tickets\GetTicket;
use Guzzle\Service\Unfuddle\Command\Tickets\DeleteTicket;
use Guzzle\Service\Unfuddle\Command\Tickets\UpdateTicket;

/**
 * @group Unfuddle
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class TicketTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Unfuddle\Command\Tickets\GetTicket
     */
    public function testGetTicket()
    {
        // Test getting all tickets
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new GetTicket();
        $command->setProjectId(1);
        $this->setMockResponse($client, 'default');
        $client->execute($command);
        $this->assertContains('GET /api/v1/projects/1/tickets HTTP/1.1', (string)$command->getRequest());

        // Test getting by ID
        $command = new GetTicket();
        $command->setProjectId(1)->setId(1);
        $this->setMockResponse($client, 'default');
        $client->execute($command);
        $this->assertContains('GET /api/v1/projects/1/tickets/1 HTTP/1.1', (string)$command->getRequest());

        // Test getting by number
        $command = new GetTicket();
        $command->setProjectId(1)->setTicketNumber(1);
        $this->setMockResponse($client, 'default');
        $client->execute($command);
        $this->assertContains('GET /api/v1/projects/1/tickets/by_number/1 HTTP/1.1', (string)$command->getRequest());
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\Tickets\DeleteTicket
     */
    public function testDeleteTicket()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new DeleteTicket();
        $command->setProjectId(1);
        $this->assertEquals($command, $command->setId(1));
        $this->setMockResponse($client, 'default');
        $client->execute($command);
        $this->assertContains('DELETE /api/v1/projects/1/tickets/1 HTTP/1.1', (string)$command->getRequest());
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\Tickets\UpdateTicket
     * @covers Guzzle\Service\Unfuddle\Command\Tickets\AbstractTicketBodyCommand
     */
    public function testUpdateTicket()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new UpdateTicket();
        $command->setProjectId(1);
        $this->assertEquals($command, $command->setId(1));
        $command->setAssigneeId(1)
                ->setComponentId(1)
                ->setDescription('abc')
                ->setDueOn('2010-11-20 1984')
                ->setHoursEstimateCurrent('2')
                ->setHoursEstimateInitial('1')
                ->setMilestoneId(1)
                ->setPriority(1)
                ->setResolution('fixed')
                ->setResolutionDescription('res descript')
                ->setSeverityId(2)
                ->setStatus('resolved');
        
        $this->setMockResponse($client, 'default');
        $client->execute($command);
        $message = (string)$command->getRequest();
        $this->assertContains('PUT /api/v1/projects/1/tickets/1 HTTP/1.1', $message);
        $this->assertContains('<ticket><assignee-id>1</assignee-id><component-id>1</component-id><description>abc</description><due-on>2010-11-20 1984</due-on><hours-estimate-current>2</hours-estimate-current><hours-estimate-initial>1</hours-estimate-initial><milestone-id>1</milestone-id><priority>1</priority><resolution>fixed</resolution><resolution-description>res descript</resolution-description><severity-id>2</severity-id><status>resolved</status></ticket>', $message);
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\Tickets\CreateTicket
     * @covers Guzzle\Service\Unfuddle\Command\Tickets\AbstractTicketBodyCommand
     */
    public function testCreateTicket()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new CreateTicket();
        $command->setProjectId(1);
        $command->setPriority(1)
            ->setSummary('Summary')
            ->setDescription('description');
        $this->setMockResponse($client, 'default');
        $client->execute($command);
        $message = (string)$command->getRequest();
        $this->assertContains('POST /api/v1/projects/1/tickets HTTP/1.1', $message);
        $this->assertContains('<ticket><priority>1</priority><summary>Summary</summary><description>description</description></ticket>', $message);
    }
}