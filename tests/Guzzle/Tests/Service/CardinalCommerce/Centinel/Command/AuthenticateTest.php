<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Service\CardinalCommerce\Centinel\Command\Authenticate;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AuthenticateTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\Command\Authenticate
     */
    public function testAuthenticate()
    {
        $auth = new Authenticate();
        $auth->setTransactionType(CentinelClient::TYPE_CREDIT_CARD);
        $auth->setParEsPayload('test');
        $auth->setOrderId('123');

        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->setMockResponse($client, 'AuthenticateResponse');
        $client->execute($auth);

        $this->assertContains('cmpi_authenticate', (string)$auth->getRequest());
        
        $xml = new \SimpleXMLElement(trim($auth->getRequest()->getPostFields()->get('cmpi_msg')));
        $this->assertNotEmpty((string)$xml->PAResPayload);

        $xml = $auth->getResult();
        $this->assertInstanceOf('SimpleXMLElement', $xml);
        $this->assertEquals('k4Vf36ijnJX54kwHQNqUr8/ruvs=', (string)$xml->Xid);
    }
}