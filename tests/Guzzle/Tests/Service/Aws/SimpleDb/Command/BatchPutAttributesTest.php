<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb\Command;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class BatchPutAttributesTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractBatchedCommand
     */
    public function testHoldsBatchedItemCollection()
    {
        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes();

        $this->assertInstanceOf('Guzzle\\Service\\Aws\\SimpleDb\\Model\\BatchedItemCollection', $command->getBatchedItemCollection());
        $collection = $command->getBatchedItemCollection();
        $newCollection = new \Guzzle\Service\Aws\SimpleDb\Model\BatchedItemCollection();
        $this->assertSame($command, $command->setBatchedItemCollection($newCollection));

        $this->assertNotSame($collection, $command->getBatchedItemCollection());
        $this->assertSame($newCollection, $command->getBatchedItemCollection());
    }

    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes::addItems
     * @covers Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes::clearItems
     * @covers Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes::getItems
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractBatchedCommand
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractSimpleDbCommandRequiresDomain::setDomain
     */
    public function testQueuesItemsForSending()
    {
        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes();

        $this->assertSame($command, $command->setDomain('test'));
        $this->assertSame($command, $command->addItems(array(
            'itemName1' => array(
                'attributeName1' => 'attributeValue1',
                'attributeName2' => array(
                    'attributeValue2_1',
                    'attributeValue2_2'
                )
            ),
            'itemName2' => array(
                'attributeName1' => 'attributeValue1',
                '_replace' => true
            )
        )));

        $this->assertEquals(array (
            'Item.1.ItemName' => 'itemName1',
            'Item.1.Attribute.0.Name' => 'attributeName1',
            'Item.1.Attribute.0.Value' => 'attributeValue1',
            'Item.1.Attribute.1.Name' => 'attributeName2',
            'Item.1.Attribute.1.Value' => 'attributeValue2_1',
            'Item.1.Attribute.2.Name' => 'attributeName2',
            'Item.1.Attribute.2.Value' => 'attributeValue2_2',
            'Item.2.ItemName' => 'itemName2',
            'Item.2.Attribute.0.Name' => 'attributeName1',
            'Item.2.Attribute.0.Value' => 'attributeValue1',
            'Item.2.Attribute.0.Replace' => 'true',
        ), $command->getBatchedItemCollection()->getItems(true));

        $this->assertSame($command, $command->clearItems());
        $this->assertEquals(array(), $command->getItems());
    }

    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes::prepare
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractSimpleDbCommandRequiresDomain::prepare
     * @expectedException InvalidArgumentException
     */
    public function testThrowsExceptionWithNoDomain()
    {
        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes();
        $client->execute($command);
    }

    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes::prepare
     * @covers Guzzle\Service\Aws\SimpleDb\Command\AbstractSimpleDbCommandRequiresDomain::prepare
     */
    public function testPreparesCommand()
    {
        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\BatchPutAttributes();
        $command->setDomain('test');
        $command->addItems(array(
            'item_1' => array(
                'attribute_1' => 'value_1'
            )
        ));
        $this->setMockResponse($client, 'BatchPutAttributesResponse');
        $client->execute($command);

        $request = $command->getRequest();
        
        // Validate the QueryString values
        $this->assertEquals('BatchPutAttributes', $request->getQuery()->get('Action'));
        $this->assertEquals('test', $request->getQuery()->get('DomainName'));
        $this->assertEquals('item_1', $request->getQuery()->get('Item.1.ItemName'));
        $this->assertEquals('attribute_1', $request->getQuery()->get('Item.1.Attribute.0.Name'));
        $this->assertEquals('value_1', $request->getQuery()->get('Item.1.Attribute.0.Value'));
        $this->assertEquals('sdb.amazonaws.com', $request->getHost());
        
        // Make sure the command was signed
        $this->assertTrue($request->getQuery()->hasKey('Timestamp'));
        $this->assertTrue($request->getQuery()->hasKey('Version'));
        $this->assertTrue($request->getQuery()->hasKey('SignatureVersion'));
        $this->assertTrue($request->getQuery()->hasKey('SignatureMethod'));
        $this->assertTrue($request->getQuery()->hasKey('AWSAccessKeyId'));
        $this->assertTrue($request->getQuery()->hasKey('Signature'));

        // Make sure the result was converted into a SimpleXMLElement
        $this->assertInstanceOf('SimpleXMLElement', $command->getResult());
    }
}