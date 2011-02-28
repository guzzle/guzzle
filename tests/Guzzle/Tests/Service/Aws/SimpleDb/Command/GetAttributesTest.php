<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb\Command;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GetAttributesTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\GetAttributes
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractAttributeCommand
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractSimpleDbCommand
     */
    public function testGetAttributes()
    {
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\GetAttributes();
        $this->assertSame($command, $command->setDomain('test'));
        $this->assertSame($command, $command->setItemName('item_name'));
        $this->assertSame($command, $command->setAttributeNames(array('attr1', 'attr2')));
        $this->assertSame($command, $command->setConsistentRead(true));

        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $this->setMockResponse($client, 'GetAttributesResponse');

        $client->execute($command);

        $this->assertContains(
            'http://sdb.amazonaws.com/?Action=GetAttributes&DomainName=test&ItemName=item_name&AttributeName.0=attr1&AttributeName.1=attr2&ConsistentRead=true&Timestamp=',
            $command->getRequest()->getUrl()
        );

        $this->assertEquals(array (
            'attr_1' => 'value_1',
            'attr_2' => array ('value_2', 'value_3', 'value_4')
        ), $command->getResult());
    }
}