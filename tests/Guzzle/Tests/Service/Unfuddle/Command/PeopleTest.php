<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Unfuddle\Command;

use Guzzle\Service\Unfuddle\Command\People\GetCurrentPerson;
use Guzzle\Service\Unfuddle\Command\People\GetPeople;
use Guzzle\Service\Unfuddle\Command\People\GetPerson;

/**
 * @group Unfuddle
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PeopleTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Unfuddle\Command\People\GetCurrentPerson
     * @covers Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand
     */
    public function testCommandGetsCurrentPerson()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new GetCurrentPerson();
        $this->assertSame($command, $command->setProjectId(1));
        $this->setMockResponse($client, 'people.get_person');
        $client->execute($command);

        $this->assertContains('/api/v1/people/current', $command->getRequest()->getUrl());
        $this->assertEquals('test@test.com', (string)$command->getResult()->email);
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\People\GetPeople
     * @covers Guzzle\Service\Unfuddle\Command\AbstractUnfuddleCommand
     */
    public function testCommandGetsPeople()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new GetPeople(array(
            'projects' => 1
        ));

        $this->setMockResponse($client, 'people.get_people');
        $client->execute($command);
        
        $this->assertContains('/api/v1/projects/1/people', $command->getRequest()->getUrl());
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\People\GetPerson
     */
    public function testCommandGetsSpecificPerson()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new GetPerson();

        $this->assertSame($command, $command->setId('1'));

        $this->setMockResponse($client, 'people.get_person');
        $client->execute($command);

        $this->assertContains('/api/v1/people/1', $command->getRequest()->getUrl());
    }
}