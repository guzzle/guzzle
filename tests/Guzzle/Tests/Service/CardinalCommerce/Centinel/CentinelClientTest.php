<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\CardinalCommerce\Centinel;

use Guzzle\Http\QueryString;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CentinelClientTest extends \Guzzle\Tests\GuzzleTestCase
{
     /**
      * @covers Guzzle\Service\CardinalCommerce\Centinel\CentinelClient
     */
    public function testConstructor()
    {
        $client = $this->getServiceBuilder()->getClient('test.centinel');
        $this->assertEquals('https://centineltest.cardinalcommerce.com/maps/txns.asp', $client->getBaseUrl());

        $this->assertEquals('test', $client->getConfig('password'));
        $this->assertEquals('123', $client->getConfig('processor_id'));
        $this->assertEquals('456', $client->getConfig('merchant_id'));
    }

    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\CentinelClient
     */
    public function testHandlesPayloads()
    {
        $client = $this->getServiceBuilder()->getClient('test.centinel');

        // Make sure empty values just return an empty array
        $this->assertEquals(array(), $client->unpackPayload(''));
        
        $payload = $client->generatePayload(array(
            'test' => 'data',
            'abc' => '123',
            'TransactionPwd' => 'abc'
        ));

        $unpacked = $client->unpackPayload($payload);
        $this->assertArrayHasKey('Hash', $unpacked);
        $this->assertArrayNotHasKey('TransactionPwd', $unpacked);
        unset($unpacked['Hash']);
        $this->assertEquals('data', $unpacked['test']);
        $this->assertEquals('123', $unpacked['abc']);
    }

    /**
     * @covers Guzzle\Service\CardinalCommerce\Centinel\CentinelClient
     * @expectedException Guzzle\Service\CardinalCommerce\Centinel\InvalidPayloadException
     */
    public function testHandlesPayloadValidation()
    {
        $client = $this->getServiceBuilder()->getClient('test.centinel');

        $payload = $client->generatePayload(array(
            'test' => 'data',
            'abc' => '123'
        ));
        parse_str($payload, $data);
        $data['Hash'] = 'invalid';
        $data = new QueryString($data);
        $data->setPrefix('');
        $payload = (string)$data;

        $unpacked = $client->unpackPayload($payload);
    }
}