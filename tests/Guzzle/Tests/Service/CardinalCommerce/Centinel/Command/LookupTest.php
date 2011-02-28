<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Service\CardinalCommerce\Centinel\Command\Lookup;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class LookupTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Lookup
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Txn
     */
    public function testLookup()
    {
        $c = new Lookup();
        $this->assertSame($c, $c->setAmount(1200.50));
        $this->assertSame($c, $c->setAquirerPassword('abc'));
        $this->assertSame($c, $c->setBrowserHeader('text/xml'));
        $this->assertSame($c, $c->setCreditCard('12349876123409876', '06', '2012'));
        $this->assertSame($c, $c->setCurrencyCode(CentinelClient::CURRENCY_US));
        $this->assertSame($c, $c->setEmail('test@test.com'));
        $this->assertSame($c, $c->setInstallment(1));
        $this->assertSame($c, $c->setIpAddress('192.168.16.121'));
        $this->assertSame($c, $c->setMerchantData('this=1&that=2'));
        $this->assertSame($c, $c->setMerchantReferenceNumber('JarJar Binks'));
        $this->assertSame($c, $c->setOrderChannel(CentinelClient::CHAN_CART));
        $this->assertSame($c, $c->setOrderDescription('Test order'));
        $this->assertSame($c, $c->setOrderNumber('1234'));
        $this->assertSame($c, $c->setProductCode(CentinelClient::CODE_PHY));
        $this->assertSame($c, $c->setRecurring('28', '20121231'));
        $this->assertSame($c, $c->setShippingAmount(12));
        $this->assertSame($c, $c->setTaxAmount(2.30));
        $this->assertSame($c, $c->setTransactionMode(CentinelClient::MODE_ECOMMERCE));
        $this->assertSame($c, $c->setTransactionType(CentinelClient::TYPE_CREDIT_CARD));
        $this->assertSame($c, $c->setUserAgent('Test-Agent'));

        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->setMockResponse($client, 'LookupResponse');
        $client->execute($c);

        $xml = new \SimpleXMLElement($c->getRequest()->getPostFields()->get('cmpi_msg'));

        // Test that the above values were set correctly on the request message
        $this->assertEquals('1.7', (string)$xml->Version);
        $this->assertEquals('123', (string)$xml->ProcessorId);
        $this->assertEquals('456', (string)$xml->MerchantId);
        $this->assertEquals('test', (string)$xml->TransactionPwd);
        $this->assertEquals('PHPTC', (string)$xml->Source);
        $this->assertEquals('1.7', (string)$xml->SourceVersion);
        $this->assertEquals('cmpi_lookup', (string)$xml->MsgType);
        $this->assertEquals('120050', (string)$xml->Amount);
        $this->assertEquals('abc', (string)$xml->AquirerPassword);
        $this->assertEquals('text/xml', (string)$xml->BrowserHeader);
        $this->assertEquals('12349876123409876', (string)$xml->CardNumber);
        $this->assertEquals('06', (string)$xml->CardExpMonth);
        $this->assertEquals('2012', (string)$xml->CardExpYear);
        $this->assertEquals('840', (string)$xml->CurrencyCode);
        $this->assertEquals('test@test.com', (string)$xml->Email);
        $this->assertEquals('1', (string)$xml->Installment);
        $this->assertEquals('192.168.16.121', (string)$xml->IPAddress);
        $this->assertEquals('this=1&that=2', (string)$xml->MerchantData);
        $this->assertEquals('JarJar Binks', (string)$xml->MerchantReferenceNumber);
        $this->assertEquals('CART', (string)$xml->OrderChannel);
        $this->assertEquals('Test order', (string)$xml->OrderDescription);
        $this->assertEquals('1234', (string)$xml->OrderNumber);
        $this->assertEquals('PHY', (string)$xml->ProductCode);
        $this->assertEquals('Y', (string)$xml->Recurring);
        $this->assertEquals('28', (string)$xml->RecurringFrequency);
        $this->assertEquals('20121231', (string)$xml->RecurringEnd);
        $this->assertEquals('1200', (string)$xml->ShippingAmount);
        $this->assertEquals('230', (string)$xml->TaxAmount);
        $this->assertEquals('S', (string)$xml->TransactionMode);
        $this->assertEquals('C', (string)$xml->TransactionType);
        $this->assertEquals('Test-Agent', (string)$xml->UserAgent);
        $this->assertEquals('15000', (string)$xml->ResolveTimeout);
        $this->assertEquals('15000', (string)$xml->SendTimeout);
        $this->assertEquals('15000', (string)$xml->ReceiveTimeout);
        $this->assertEquals('15000', (string)$xml->ConnectTimeout);
        $this->assertEquals('https://centineltest.cardinalcommerce.com/maps/txns.asp', (string)$xml->TransactionUrl);
        $this->assertNotEmpty((string)$xml->MerchantSystemDate);

        $this->assertInstanceOf('SimpleXMLElement', $c->getResult());
    }
    
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Lookup
     * @expectedException InvalidArgumentException
     */
    public function testValidatesProductData()
    {
        $c = new Lookup();
        $c->addProduct(array(
            'price' => '12.99',
        ));
        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $c->prepare($client);
    }

    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Lookup
     */
    public function testCorrectlyAddsProducts()
    {
        $c = new Lookup();
        $c->setTransactionType(CentinelClient::TYPE_CREDIT_CARD);
        $c->addProduct(array(
            'price' => '12.99',
            'description' => 'Test product',
            'sku' => 'XYZ',
            'qty' => 1,
            'name' => 'Product name 1',
            'Custom_%s' => 'custom_1',
            'Item_%s_Custom' => 'custom_2'
        ));

        $c->addProduct(array(
            'price' => '13.99',
            'description' => 'Test product 2',
            'sku' => 'XYZ2',
            'qty' => 2,
            'name' => 'Product name 2',
            'Custom_%s' => 'custom_3',
            'Item_%s_Custom' => 'custom_4'
        ));

        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $xml = new \SimpleXMLElement(trim($c->prepare($client)->getPostFields()->get('cmpi_msg')));

        // Test that the above values were set correctly on the request message
        $this->assertEquals('1299', (string)$xml->Item_Price_1);
        $this->assertEquals('1', (string)$xml->Item_Quantity_1);
        $this->assertEquals('XYZ', (string)$xml->Item_SKU_1);
        $this->assertEquals('Test product', (string)$xml->Item_Desc_1);
        $this->assertEquals('Product name 1', (string)$xml->Item_Name_1);
        $this->assertEquals('custom_1', (string)$xml->Custom_1);
        $this->assertEquals('custom_2', (string)$xml->Item_1_Custom);

        $this->assertEquals('1399', (string)$xml->Item_Price_2);
        $this->assertEquals('2', (string)$xml->Item_Quantity_2);
        $this->assertEquals('XYZ2', (string)$xml->Item_SKU_2);
        $this->assertEquals('Test product 2', (string)$xml->Item_Desc_2);
        $this->assertEquals('Product name 2', (string)$xml->Item_Name_2);
        $this->assertEquals('custom_3', (string)$xml->Custom_2);
        $this->assertEquals('custom_4', (string)$xml->Item_2_Custom);
    }
}