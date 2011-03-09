<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Builder;

use Doctrine\Common\Cache\ArrayCache;
use Guzzle\Common\Cache\DoctrineCacheAdapter;
use Guzzle\Service\Builder\DefaultBuilder;

/**
 * @group Builder
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DefaultBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Builder\AbstractBuilder::__construct
     * @covers Guzzle\Service\Builder\DefaultBuilder::getConfig
     * @covers Guzzle\Service\Builder\AbstractBuilder::getName
     * @covers Guzzle\Service\Builder\AbstractBuilder::setName
     */
    public function testConstructor()
    {
        $builder = new DefaultBuilder(array(
            'key' => 'value'
        ), 'test');

        // Test the name of the builder
        $this->assertEquals('test', $builder->getName());
        $this->assertEquals($builder, $builder->setName('whodat'));
        $this->assertEquals('whodat', $builder->getName());

        $this->assertEquals(array('key' => 'value'), $builder->getConfig()->getAll());
    }

    /**
     * @covers Guzzle\Service\Builder\AbstractBuilder::setCache
     */
    public function testHasCache()
    {
        $builder = new DefaultBuilder(array(
            'key' => 'value'
        ), 'test');

        $cacheAdapter = new DoctrineCacheAdapter(new ArrayCache());

        // Test the name of the builder
        $this->assertSame($builder, $builder->setCache($cacheAdapter, 1234));
    }

    /**
     * @covers Guzzle\Service\Builder\DefaultBuilder::getClass
     * @covers Guzzle\Service\Builder\DefaultBuilder::setClass
     * @covers Guzzle\Service\Builder\DefaultBuilder::build
     */
    public function testDefaultBuilderHasClass()
    {
        $builder = new DefaultBuilder(array(
            'key' => 'value'
        ), 'test');

        try {
            $builder->build();
            $this->fail('Exception not thrown when building without a class when using the default builder');
        } catch (\Guzzle\Service\ServiceException $e) {}

        $this->assertEquals($builder, $builder->setClass('abc.123'));

        // The builder will convert lowercase and periods
        $this->assertEquals('Abc\\123', $builder->getClass());

        try {
            $builder->build();
            $this->fail('Exception not thrown when building with an invalid class');
        } catch (\Guzzle\Service\ServiceException $e) {}
    }

    /**
     * @covers Guzzle\Service\Builder\DefaultBuilder::build
     */
    public function testBuildsClients()
    {
        $builder = new DefaultBuilder(array(
            'username' => 'michael',
            'password' => 'test',
            'subdomain' => 'michael'
        ), 'michael.unfuddle');

        $builder->setClass('Guzzle\\Tests\\Service\\Mock\\MockClient');

        $client = $builder->build();
        $this->assertInstanceOf('Guzzle\\Tests\\Service\\Mock\\MockClient', $client);

        // make sure a service was created correctly
        $this->assertTrue($client->getService()->hasCommand('sub.sub'));
        $this->assertTrue($client->getService()->hasCommand('mock_command'));
        $this->assertTrue($client->getService()->hasCommand('other_command'));
    }

    /**
     * @covers Guzzle\Service\Builder\AbstractBuilder::__toString
     */
    public function testConvertsToXmlString()
    {
        $builder = new DefaultBuilder(array(
            'username' => 'michael',
            'password' => 'test',
            'subdomain' => 'michael'
        ), 'mock');

        $builder->setClass('Guzzle\\Tests\\Service\\Mock\\MockClient');

        $xml = <<<EOT
<service name="mock" class="Guzzle.Tests.Service.Mock.MockClient">
    <param name="username" value="michael" />
    <param name="password" value="test" />
    <param name="subdomain" value="michael" />
</service>
EOT;
        $xml = trim($xml);

        $this->assertEquals($xml, (string) $builder);
    }

    /**
     * @covers Guzzle\Service\Builder\DefaultBuilder
     */
    public function testUsesCache()
    {
        $cache = new ArrayCache();
        $adapter = new DoctrineCacheAdapter($cache);
        $this->assertEmpty($cache->getIds());
        $builder = new DefaultBuilder(array(
            'username' => 'michael',
            'password' => 'test',
            'subdomain' => 'michael'
        ), 'michael.unfuddle');

        $builder->setClass('Guzzle\\Tests\\Service\\Mock\\MockClient');
        $this->assertSame($builder, $builder->setCache($adapter));

        $client1 = $builder->build();

        $this->assertNotEmpty($cache->getIds());

        $client2 = $builder->build();
        $this->assertEquals($client1, $client2);
        $this->assertNotNull($client2->getConfig('_service_from_cache'));
    }
}