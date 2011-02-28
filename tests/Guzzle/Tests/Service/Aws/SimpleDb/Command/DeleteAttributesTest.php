<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb\Command;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DeleteAttributesTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\DeleteAttributes
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractAttributeCommand
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractSimpleDbCommand
     */
    public function testDeleteAttributes()
    {
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\DeleteAttributes();
        $this->assertSame($command, $command->setDomain('test'));
        $this->assertSame($command, $command->setItemName('item_name'));
        $this->assertSame($command, $command->setAttributeNames(array('attr1', 'attr2')));
        $this->assertSame($command, $command->addExpected('test_attr', 'abc', true));

        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $this->setMockResponse($client, 'DeleteAttributesResponse');

        $client->execute($command);

        $this->assertContains(
            'http://sdb.amazonaws.com/?Action=DeleteAttributes&DomainName=test&ItemName=item_name&AttributeName.0=attr1&AttributeName.1=attr2&Expected.0.Name=test_attr&Expected.0.Value=abc&Expected.0.Exists=true&Timestamp=',
            $command->getRequest()->getUrl()
        );
    }
}