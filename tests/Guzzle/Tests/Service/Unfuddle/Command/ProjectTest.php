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
class ProjectTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Unfuddle\Command\Projects\Components\GetComponent
     */
    public function testGetComponents()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new \Guzzle\Service\Unfuddle\Command\Projects\Components\GetComponent(array(
            'projects' => 1
        ));
        $this->setMockResponse($client, 'people.get_person'); // We don't care what the response is, just that the request is correct
        $client->execute($command);
        $this->assertContains('/api/v1/projects/1/components', $command->getRequest()->getUrl());

        // Now get a specific component
        $command = new \Guzzle\Service\Unfuddle\Command\Projects\Components\GetComponent(array(
            'projects' => 1
        ));
        $command->setCompnentId(1);
        $this->setMockResponse($client, 'people.get_person'); // We don't care what the response is, just that the request is correct
        $client->execute($command);
        $this->assertContains('/api/v1/projects/1/components/1', $command->getRequest()->getUrl());
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\Projects\Severities\GetSeverity
     */
    public function testGetSeverities()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new \Guzzle\Service\Unfuddle\Command\Projects\Severities\GetSeverity(array(
            'projects' => 1
        ));
        $this->setMockResponse($client, 'people.get_person'); // We don't care what the response is, just that the request is correct
        $client->execute($command);
        $this->assertContains('/api/v1/projects/1/severities', $command->getRequest()->getUrl());

        // Now get a specific severity
        $command = new \Guzzle\Service\Unfuddle\Command\Projects\Severities\GetSeverity(array(
            'projects' => 1
        ));
        $command->setSeverityId(1);
        $this->setMockResponse($client, 'people.get_person'); // We don't care what the response is, just that the request is correct
        $client->execute($command);
        $this->assertContains('/api/v1/projects/1/severities/1', $command->getRequest()->getUrl());
    }

    /**
     * @covers Guzzle\Service\Unfuddle\Command\Projects\Versions\GetVersion
     */
    public function testGetVersions()
    {
        $client = $this->getServiceBuilder()->getClient('test.unfuddle');
        $command = new \Guzzle\Service\Unfuddle\Command\Projects\Versions\GetVersion(array(
            'projects' => 1
        ));
        $this->setMockResponse($client, 'people.get_person'); // We don't care what the response is, just that the request is correct
        $client->execute($command);
        $this->assertContains('/api/v1/projects/1/versions', $command->getRequest()->getUrl());

        // Now get a specific severity
        $command = new \Guzzle\Service\Unfuddle\Command\Projects\Versions\GetVersion(array(
            'projects' => 1
        ));
        $command->setVersionId(1);
        $this->setMockResponse($client, 'people.get_person'); // We don't care what the response is, just that the request is correct
        $client->execute($command);
        $this->assertContains('/api/v1/projects/1/versions/1', $command->getRequest()->getUrl());
    }
}