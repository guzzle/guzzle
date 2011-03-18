<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Builder;

use Doctrine\Common\Cache\ArrayCache;
use Guzzle\Common\Cache\DoctrineCacheAdapter;
use Guzzle\Service\Builder\DefaultBuilder;
use Guzzle\Tests\Service\Mock\MockClient;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DefaultBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Builder\DefaultBuilder::prepareConfig
     */
    public function testPreparesConfig()
    {
        $c = DefaultBuilder::prepareConfig(array(
            'a' => '123',
            'base_url' => 'http://www.test.com/'
        ), array(
            'a' => 'xyz',
            'b' => 'lol'
        ), array('a'));

        $this->assertType('Guzzle\Common\Collection', $c);
        $this->assertEquals(array(
            'a' => '123',
            'b' => 'lol',
            'base_url' => 'http://www.test.com/'
        ), $c->getAll());
    }

    /**
     * @covers Guzzle\Service\Builder\DefaultBuilder::prepareConfig
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage Client config must contain a 'a' key
     */
    public function testValidatesConfig()
    {
        $c = DefaultBuilder::prepareConfig(array(), array(), array('a'));
    }

    /**
     * @covers Guzzle\Service\Builder\DefaultBuilder::prepareConfig
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage No base_url is set in the builder config
     */
    public function testValidatesConfigContainsBaseUrl()
    {
        $c = DefaultBuilder::prepareConfig(array());
    }

    /**
     * @covers Guzzle\Service\Builder\DefaultBuilder::build
     */
    public function testAddsFactoryAndServiceToClientAndUsesCache()
    {
        $adapter = new DoctrineCacheAdapter(new ArrayCache());
        $client = MockClient::factory(array(
            'password' => 'abc',
            'username' => '123',
            'subdomain' => 'me'
        ), $adapter);

        $this->assertType('Guzzle\Tests\Service\Mock\MockClient', $client);
        $this->assertType('Guzzle\Service\ServiceDescription', $client->getService());
        $this->assertType('Guzzle\Tests\Service\Mock\Command\MockCommand', $client->getCommand('mock_command'));

        // make sure that the adapter cached the service description
        $this->assertTrue($adapter->contains('guzzle_guzzle_tests_service_mock_mockclient'));

        // Get the service description from cache
        $client = MockClient::factory(array(
            'password' => 'abc',
            'username' => '123',
            'subdomain' => 'me'
        ), $adapter);
    }
}