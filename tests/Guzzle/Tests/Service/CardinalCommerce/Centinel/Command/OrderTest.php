<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Service\CardinalCommerce\Centinel\Command\Order;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class OrderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Order
     */
    public function testOrder()
    {
        // Create a new order and check the fluent interface
        $c = new Order();
        $this->assertSame($c, $c->setCurrencyCode(CentinelClient::CURRENCY_US));
        $this->assertSame($c, $c->setAmount(100.99));
        $this->assertSame($c, $c->setTransactionType(CentinelClient::TYPE_AMAZON));
        $this->assertSame($c, $c->setOrderNumber('123'));
        $this->assertSame($c, $c->setOrderId('456'));
        $this->assertSame($c, $c->setTransactionMode(CentinelClient::MODE_MOTO));
        $this->assertSame($c, $c->setPromotionAmount(3.50));
        $this->assertSame($c, $c->setPromotionDesc('Test promo'));
        $this->assertSame($c, $c->setPromotionName('TEST'));
        $this->assertSame($c, $c->setShipLabel('Craig Ferguson'));
        $this->assertSame($c, $c->setShipMethod('STANDARD'));
        $this->assertSame($c, $c->setTransactionType(CentinelClient::TYPE_AMAZON));

        $this->assertSame($c, $c->addProduct(array(
            'qty' => 12,
            'sku' => 'XYZ',
            'price' => '12.99',
            'name' => 'test',
            'desc' => 'desc',
            'tax_amount' => '3.99',
            'ship_amount' => '1.99',
            'ship_method' => 'STANDARD',
            'ship_label' => 'Antartica',
            'product_code' => 'PHY',
            'promotion_name' => 'DISCOUNT',
            'promotion_desc' => 'Goodly',
            'promotion_amount' => '5.00'
        )));
        $this->assertSame($c, $c->addProduct(array(
            'qty' => 2,
            'sku' => 'ABC',
            'price' => '1.99',
            'name' => 'test 2',
            'desc' => 'desc 2',
            'tax_amount' => 0.99,
            'ship_amount' => 0.99,
            'ship_method' => 'STANDARD',
            'ship_label' => 'Finland',
            'product_code' => 'PHY'
        )));

        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->setMockResponse($client, 'OrderResponse');
        $client->execute($c);

        // Check the outbound cmpi_capture message
        $xml = new \SimpleXMLElement(trim($c->getRequest()->getPostFields()->get('cmpi_msg')));
        $this->assertEquals('cmpi_order', (string)$xml->MsgType);
        $this->assertEquals('350', (string)$xml->PromotionAmount);
        $this->assertEquals('Test promo', (string)$xml->PromotionDesc);
        $this->assertEquals('TEST', (string)$xml->PromotionName);
        $this->assertEquals('Craig Ferguson', (string)$xml->ShipLabel);
        $this->assertEquals('STANDARD', (string)$xml->ShipMethod);
        $this->assertEquals(CentinelClient::TYPE_AMAZON, (string)$xml->TransactionType);

        // make sure that the items were added correctly
        $this->assertEquals('12', (string)$xml->Item_Quantity_1);
        $this->assertEquals('XYZ', (string)$xml->Item_SKU_1);
        $this->assertEquals('1299', (string)$xml->Item_Price_1);
        $this->assertEquals('test', (string)$xml->Item_Name_1);
        $this->assertEquals('desc', (string)$xml->Item_Desc_1);
        $this->assertEquals('399', (string)$xml->Item_TaxAmount_1);
        $this->assertEquals('199', (string)$xml->Item_ShipAmount_1);
        $this->assertEquals('STANDARD', (string)$xml->Item_ShipMethod_1);
        $this->assertEquals('Antartica', (string)$xml->Item_ShipLabel_1);
        $this->assertEquals('PHY', (string)$xml->Item_ProductCode_1);
        $this->assertEquals('DISCOUNT', (string)$xml->Item_PromotionName_1);
        $this->assertEquals('Goodly', (string)$xml->Item_PromotionDesc_1);
        $this->assertEquals('500', (string)$xml->Item_PromotionAmount_1);

        $this->assertEquals('2', (string)$xml->Item_Quantity_2);
        $this->assertEquals('ABC', (string)$xml->Item_SKU_2);
        $this->assertEquals('199', (string)$xml->Item_Price_2);
        $this->assertEquals('test 2', (string)$xml->Item_Name_2);
        $this->assertEquals('desc 2', (string)$xml->Item_Desc_2);
        $this->assertEquals('99', (string)$xml->Item_TaxAmount_2);
        $this->assertEquals('99', (string)$xml->Item_ShipAmount_2);
        $this->assertEquals('STANDARD', (string)$xml->Item_ShipMethod_2);
        $this->assertEquals('Finland', (string)$xml->Item_ShipLabel_2);
        $this->assertEquals('PHY', (string)$xml->Item_ProductCode_2);
        $this->assertEquals('', (string)$xml->Item_PromotionName_2);
        $this->assertEquals('', (string)$xml->Item_PromotionDesc_2);
        $this->assertEquals('', (string)$xml->Item_PromotionAmount_2);

        // make sure the result is created correctly
        $xml = $c->getResult();
        $this->assertInstanceOf('SimpleXMLElement', $xml);
        $this->assertEquals('8604929789808576', (string)$xml->OrderId);
    }

    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Order
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage name, desc, price, and are required for each product
     */
    public function testOrderThrowsExceptionWhenMissingProductData()
    {
        // Create a new order and check the fluent interface
        $c = new Order();
        $this->assertSame($c, $c->addProduct(array()));
    }
}