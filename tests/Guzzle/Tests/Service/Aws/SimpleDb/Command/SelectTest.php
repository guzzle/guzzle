<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb\Command;

use Guzzle\Service\Aws\SimpleDb\Command\Select;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SelectTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Command\Select
     */
    public function testSelect()
    {
        $client = $this->getServiceBuilder()->getClient('test.simple_db');
        $command = new Select();
        $this->assertSame($command, $command->setConsistentRead(true));
        $this->assertSame($command, $command->setXmlResponseOnly(false));
        $this->assertSame($command, $command->setNextToken(null));
        $this->assertSame($command, $command->setLimit(2));
        $this->assertSame($command, $command->setSelectExpression('select * from mydomain where Title = \'The Right Stuff\''));
        $this->assertEquals('select * from mydomain where Title = \'The Right Stuff\'', $command->getSelectExpression());

        $this->setMockResponse($client, array('SelectResponseWithNextToken'));
        $client->execute($command);

        $this->assertContains(
            'http://sdb.amazonaws.com/?Action=Select&SelectExpression=select%20%2A%20from%20mydomain%20where%20Title%20%3D%20%27The%20Right%20Stuff%27&ConsistentRead=true&Timestamp=',
            $command->getRequest()->getUrl()
        );

        $this->assertInstanceOf('Guzzle\Service\Aws\SimpleDb\Model\SelectIterator', $command->getResult());
    }
}