<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb\Command;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DeleteDomainTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\DeleteDomain
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractSimpleDbCommand
     */
    public function testDeleteDomain()
    {
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\DeleteDomain();
        $command->setDomain('test');

        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $this->setMockResponse($client, 'DeleteDomainResponse');
        $client->execute($command);

        $this->assertContains('http://sdb.amazonaws.com/?Action=DeleteDomain&DomainName=test&Timestamp=', $command->getRequest()->getUrl());
    }
}