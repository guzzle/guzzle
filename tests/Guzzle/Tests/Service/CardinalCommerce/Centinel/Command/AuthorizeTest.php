<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Service\CardinalCommerce\Centinel\Command\Authorize;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AuthorizeTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Authorize
     */
    public function testAuthorize()
    {
        $c = new Authorize();
        $this->assertSame($c, $c->setTransactionType('Ac'));
        $this->assertSame($c, $c->setOrderId('123'));
        $this->assertSame($c, $c->setOrderDescription('description'));

        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->setMockResponse($client, 'AuthorizeResponse');
        $client->execute($c);

        $xml = new \SimpleXMLElement(trim($c->getRequest()->getPostFields()->get('cmpi_msg')));
        $this->assertEquals('cmpi_authorize', (string)$xml->MsgType);
        $this->assertEquals('123', (string)$xml->OrderId);
        $this->assertEquals('description', (string)$xml->OrderDescription);

        $xml = $c->getResult();
        $this->assertInstanceOf('SimpleXMLElement', $xml);
        $this->assertEquals('7fDSaySnCmDGCjPglzqX', (string)$xml->TransactionId);
    }
}