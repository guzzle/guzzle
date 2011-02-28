<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Service\CardinalCommerce\Centinel\Command\Refund;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class RefundTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Refund
     */
    public function testRefund()
    {
        $c = new Refund();
        $this->assertSame($c, $c->setTransactionType(CentinelClient::TYPE_AMAZON));
        $this->assertSame($c, $c->setOrderDescription('desc'));
        $this->assertSame($c, $c->setOrderId('123'));
        $this->assertSame($c, $c->setReason('Fraud'));
        $this->assertSame($c, $c->setAmount('12.99'));
        $this->assertSame($c, $c->addProduct('XYZ', '8.99'));
        $this->assertSame($c, $c->addProduct('XYZ-AB', '4'));
        $this->assertSame($c, $c->setCurrencyCode(CentinelClient::CURRENCY_US));

        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->setMockResponse($client, 'RefundResponse');
        $client->execute($c);

        // Validate the XML message
        $xml = new \SimpleXMLElement(trim($c->getRequest()->getPostFields()->get('cmpi_msg')));
        $this->assertEquals('desc', (string)$xml->OrderDescription);
        $this->assertEquals('123', (string)$xml->OrderId);
        $this->assertEquals('Fraud', (string)$xml->Reason);
        $this->assertEquals('Ac', (string)$xml->TransactionType);
        $this->assertEquals('1299', (string)$xml->Amount);
        $this->assertEquals('XYZ', (string)$xml->Item_SKU_1);
        $this->assertEquals('899', (string)$xml->Item_Price_1);
        $this->assertEquals('XYZ-AB', (string)$xml->Item_SKU_2);
        $this->assertEquals('400', (string)$xml->Item_Price_2);

        // Validate the response
        $xml = $c->getResult();
        $this->assertInstanceOf('SimpleXMLElement', $xml);
        $this->assertEquals('Tried to refund more than Order Amount', (string)$xml->ReasonDesc);
    }
}