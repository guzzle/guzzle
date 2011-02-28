<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Service\CardinalCommerce\Centinel\Command\InitiateOrder;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class InitiateOrderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\InitiateOrder
     */
    public function testInitiateOrder()
    {
        $c = new InitiateOrder();
        $this->assertSame($c, $c->setAmount(19.99));
        $this->assertSame($c, $c->setBrowserHeader('header'));
        $this->assertSame($c, $c->setCurrencyCode(CentinelClient::CURRENCY_US));
        $this->assertSame($c, $c->setIpAddress('127.0.0.1'));
        $this->assertSame($c, $c->setMerchantData('this=1&that=2'));
        $this->assertSame($c, $c->setOrderDescription('desc'));
        $this->assertSame($c, $c->setOrderNumber('123'));
        $this->assertSame($c, $c->setPaymentProcessorOrderId('abc'));
        $this->assertSame($c, $c->setTransactionType(CentinelClient::TYPE_AMAZON));
        $this->assertSame($c, $c->setUserAgent('agent'));

        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->setMockResponse($client, 'InitiateOrderResponse');
        $client->execute($c);

        // Check the generated message
        $xml = new \SimpleXMLElement(trim($c->getRequest()->getPostFields()->get('cmpi_msg')));
        $this->assertEquals('cmpi_initiate_order', (string)$xml->MsgType);
        $this->assertEquals('1999', (string)$xml->Amount);
        $this->assertEquals('header', (string)$xml->BrowserHeader);
        $this->assertEquals(CentinelClient::CURRENCY_US, (string)$xml->CurrencyCode);
        $this->assertEquals('127.0.0.1', (string)$xml->IPAddress);
        $this->assertEquals('this=1&that=2', (string)$xml->MerchantData);
        $this->assertEquals('desc', (string)$xml->OrderDescription);
        $this->assertEquals('123', (string)$xml->OrderNumber);
        $this->assertEquals('abc', (string)$xml->PaymentProcessorOrderId);
        $this->assertEquals(CentinelClient::TYPE_AMAZON, (string)$xml->TransactionType);
        $this->assertEquals('agent', (string)$xml->UserAgent);

        // make sure the response is parsed into XML correctly
        $xml = $c->getResult();
        $this->assertInstanceOf('SimpleXMLElement', $xml);
        $this->assertEquals('PENDING', (string)$xml->TransactionState);
    }
}