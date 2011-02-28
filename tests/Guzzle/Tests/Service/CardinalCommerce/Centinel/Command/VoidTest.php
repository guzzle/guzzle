<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Service\CardinalCommerce\Centinel\Command\Void;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class VoidTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Void
     */
    public function testVoid()
    {
        $c = new Void();
        $this->assertSame($c, $c->setTransactionType(CentinelClient::TYPE_AMAZON));
        $this->assertSame($c, $c->setOrderDescription('desc'));
        $this->assertSame($c, $c->setOrderId('123'));
        $this->assertSame($c, $c->setReason('Fraud'));

        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->setMockResponse($client, 'VoidResponse');
        $client->execute($c);

        // Validate the XML message
        $xml = new \SimpleXMLElement(trim($c->getRequest()->getPostFields()->get('cmpi_msg')));
        $this->assertEquals('desc', (string)$xml->OrderDescription);
        $this->assertEquals('123', (string)$xml->OrderId);
        $this->assertEquals('Fraud', (string)$xml->Reason);
        $this->assertEquals('Ac', (string)$xml->TransactionType);

        // Validate the response
        $xml = $c->getResult();
        $this->assertInstanceOf('SimpleXMLElement', $xml);
        $this->assertEquals('Invalid void request: You can\'t void a charged order', (string)$xml->ReasonDesc);
    }
}