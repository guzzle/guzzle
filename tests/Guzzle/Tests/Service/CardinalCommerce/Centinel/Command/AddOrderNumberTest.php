<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Service\CardinalCommerce\Centinel\Command\AddOrderNumber;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AddOrderNumberTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\AddOrderNumber
     */
    public function testAddOrderNumber()
    {
        $c = new AddOrderNumber();
        $this->assertSame($c, $c->setOrderId('8604929789808576'));
        $this->assertSame($c, $c->setOrderNumber('abc'));
        $this->assertSame($c, $c->setTransactionType(CentinelClient::TYPE_AMAZON));
        
        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->setMockResponse($client, 'AddOrderNumberResponse');
        $client->execute($c);
        $xml = new \SimpleXMLElement(trim($c->getRequest()->getPostFields()->get('cmpi_msg')));

        $this->assertEquals('cmpi_add_order_number', (string)$xml->MsgType);
        $this->assertEquals('abc', (string)$xml->OrderNumber);
        $this->assertEquals('8604929789808576', (string)$xml->OrderId);
        $this->assertEquals('Ac', (string)$xml->TransactionType);

        $xml = $c->getResult();
        $this->assertInstanceOf('SimpleXMLElement', $xml);
        $this->assertEquals('8604929789808576', (string)$xml->OrderId);
    }
}