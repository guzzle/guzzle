<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb\Model;

use Guzzle\Service\Aws\SimpleDb\Command\Select;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SelectIteratorTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\ResourceIterator
     * @covers Guzzle\Service\Aws\SimpleDb\Model\SelectIterator
     * @covers Guzzle\Service\Aws\SimpleDb\Command\Select
     */
    public function testSelectIterator()
    {
        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $command = new Select();
        $command->setSelectExpression('select * from mydomain where Title = \'The Right Stuff\'');
        $this->setMockResponse($client, array('SelectResponseWithNextToken', 'SelectResponse'));
        $client->execute($command);
        $this->assertInstanceOf('Guzzle\Service\Aws\SimpleDb\Model\SelectIterator', $command->getResult());

        $this->assertEquals('select * from mydomain where Title = \'The Right Stuff\'', $command->getResult()->getSelectExpression());
        $this->assertFalse($command->getResult()->isConsistentRead());

        $this->assertEquals(2, count($command->getResult()));
        $this->assertEquals('string', $command->getResult()->getNextToken());
        
        $all = array();
        $t = -1;
        foreach ($command->getResult() as $key => $value) {
            $all[$key] = $value;
            $this->assertEquals(++$t, $command->getResult()->getPosition());
        }

        $this->assertEquals(array(
            'item_name1' => array(
                'attr_1' => array('value_1', 'value_2', 'value_3'),
                'attr_2' => 'value_4'
            ),
            'item_name2' => array(
                'animal' => 'elephant',
            ),
            'item_name3' => array(
                'attr_3' => 'value_5',
                'attr_4' => 'value_6',
            ),
            'item_name4' => array(
                'attr_5' => 'value_7',
            ),
        ), $all);

        $requests = $this->getMockedRequests();
        $this->assertEquals(2, count($requests));
        $this->assertEquals('Select', $requests[0]->getQuery()->get('Action'));
        $this->assertEquals('Select', $requests[1]->getQuery()->get('Action'));
        $this->assertEquals('string', $requests[1]->getQuery()->get('NextToken'));
    }

    /**
     * @covers Guzzle\Service\ResourceIterator
     * @covers Guzzle\Service\ResourceIterator::current
     * @covers Guzzle\Service\ResourceIterator::key
     * @covers Guzzle\Service\ResourceIterator::calculatePageSize
     * @covers Guzzle\Service\Aws\SimpleDb\Model\SelectIterator
     * @covers Guzzle\Service\Aws\SimpleDb\Command\Select
     */
    public function testSelectIteratorWithLimit()
    {
        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $command = new Select();
        $command->setSelectExpression('select * from mydomain where Title = \'The Right Stuff\'');
        $command->setLimit(2);
        $this->setMockResponse($client, array('SelectResponseWithNextToken', 'SelectResponse'));
        $client->execute($command);
        $this->assertInstanceOf('Guzzle\Service\Aws\SimpleDb\Model\SelectIterator', $command->getResult());

        $this->assertEquals(2, count($command->getResult()));
        $all = array();
        foreach ($command->getResult() as $key => $value) {
            $all[$key] = $value;
            if ($command->getResult()->getPosition() == 0) {
                $this->assertEquals('item_name1', $command->getResult()->key());
                $this->assertEquals(array(
                    'attr_1' => array('value_1', 'value_2', 'value_3'),
                    'attr_2' => 'value_4'
                ), $command->getResult()->current());
            } else {
                $this->assertEquals('item_name2', $command->getResult()->key());
                $this->assertEquals(array(
                    'animal' => 'elephant'
                ), $command->getResult()->current());
            }
        }

        $this->assertEquals(array(
            'item_name1' => array(
                'attr_1' => array('value_1', 'value_2', 'value_3'),
                'attr_2' => 'value_4'
            ),
            'item_name2' => array(
                'animal' => 'elephant',
            )
        ), $all);
    }
}