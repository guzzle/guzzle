<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb\Command;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PutAttributesTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\PutAttributes::addAttribute
     * @covers Guzzle\Service\Aws\SimpleDb\Command\PutAttributes::addExpected
     * @covers Guzzle\Service\Aws\SimpleDb\Command\PutAttributes::setAttributes
     */
    public function testHoldsAttributes()
    {
        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\PutAttributes();
        $this->assertSame($command, $command->setDomain('test'));
        $this->assertSame($command, $command->setItemName('item'));
        $this->assertSame($command, $command->addExpected('attr_1', 'value_1', true));
        $this->assertSame($command, $command->addExpected('attr_2', 'value_2', true));
        $this->assertSame($command, $command->addAttribute('attr_1', 'new_value', true));

        $this->assertEquals('test', $command->get('domain'));
        $this->assertEquals('item', $command->get('item_name'));
        $this->assertEquals('attr_1', $command->get('Expected.0.Name'));
        $this->assertEquals('value_1', $command->get('Expected.0.Value'));
        $this->assertEquals('attr_2', $command->get('Expected.1.Name'));
        $this->assertEquals('value_2', $command->get('Expected.1.Value'));
        $this->assertEquals('attr_1', $command->get('Attribute.0.Name'));
        $this->assertEquals('new_value', $command->get('Attribute.0.Value'));
        $this->assertEquals('true', $command->get('Attribute.0.Replace'));

        $this->assertSame($command, $command->setAttributes(array(
            'attr_1' => 'value_1',
            'attr_2' => array('value_2', 'value_3')
        ), true));

        $this->assertEquals(array(
            'Attribute.0.Value' => 'value_1',
            'Attribute.0.Replace' => 'true',
            'Attribute.0.Name' => 'attr_1',
            'Attribute.1.Name' => 'attr_2',
            'Attribute.1.Replace' => 'true',
            'Attribute.1.Value' => 'value_2',
            'Attribute.2.Name' => 'attr_2',
            'Attribute.2.Replace' => 'true',
            'Attribute.2.Value' => 'value_3'
        ), $command->getAll(array('/^Attribute.+/')));
    }

    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\PutAttributes::addAttribute
     * @covers Guzzle\Service\Aws\SimpleDb\Command\PutAttributes::addExpected
     * @covers Guzzle\Service\Aws\SimpleDb\Command\PutAttributes::setAttributes
     * @covers Guzzle\Service\Aws\SimpleDb\Command\PutAttributes::build
     */
    public function testPreparesRequest()
    {
        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $command = new \Guzzle\Service\Aws\SimpleDb\Command\PutAttributes();
        $this->assertSame($command, $command->setDomain('test'));
        $this->assertSame($command, $command->setItemName('item'));
        $command->addExpected('attr_1', 'value_1', true);
        $command->addExpected('attr_2', 'value_2', true);
        $command->addAttribute('attr_1', 'new_value', true);
        $this->setMockResponse($client, 'PutAttributesResponse');
        $client->execute($command);

        $this->assertContains(
            'http://sdb.amazonaws.com/?Action=PutAttributes&DomainName=test&ItemName=item&Attribute.0.Name=attr_1&Attribute.0.Value=new_value&Attribute.0.Replace=true&Expected.0.Name=attr_1&Expected.0.Value=value_1&Expected.0.Exists=true&Expected.1.Name=attr_2&Expected.1.Value=value_2&Expected.1.Exists=true&Timestamp=',
            $command->getRequest()->getUrl()
        );
    }
}