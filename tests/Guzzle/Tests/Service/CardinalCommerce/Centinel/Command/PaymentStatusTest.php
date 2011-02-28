<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Service\CardinalCommerce\Centinel\Command\PaymentStatus;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PaymentStatusTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\PaymentStatus
     */
    public function testPaymentStatus()
    {
        $c = new PaymentStatus();
        $this->assertSame($c, $c->setNotificationId('123'));
        $this->assertSame($c, $c->setTransactionType('Ac'));
        
        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->setMockResponse($client, 'PaymentStatusResponse');
        $client->execute($c);

        $xml = new \SimpleXMLElement(trim($c->getRequest()->getPostFields()->get('cmpi_msg')));
        $this->assertEquals('cmpi_payment_status', (string)$xml->MsgType);
        $this->assertEquals('123', (string)$xml->NotificationId);
        $this->assertEquals('Ac', (string)$xml->TransactionType);

        $xml = $c->getResult();
        $this->assertInstanceOf('SimpleXMLElement', $xml);
        $this->assertEquals('OH', (string)$xml->ShippingState);
    }
}