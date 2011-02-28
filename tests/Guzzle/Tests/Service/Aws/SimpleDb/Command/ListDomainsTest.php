<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb\Command;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ListDomainsTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\ListDomains
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractSimpleDbCommand
     */
    public function testListDomains()
    {
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\ListDomains();
        $this->assertSame($command, $command->setMaxDomains(100));
        $this->assertSame($command, $command->setIterate(true));
        $this->assertSame($command, $command->setNextToken(null));

        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $this->setMockResponse($client, array('ListDomainsWithNextTokenResponse', 'ListDomainsResponse'));
        $client->execute($command);

        $this->assertEquals(array(
            'domain_1',
            'domain_2',
            'domain_3',
            'domain_4'
        ), $command->getResult());

        $this->assertContains(
            'http://sdb.amazonaws.com/?Action=ListDomains&MaxNumberOfDomains=100&Timestamp=',
            $command->getRequest()->getUrl()
        );
    }
}