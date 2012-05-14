<?php

namespace Guzzle\Tests\Service;

use Guzzle\Service\Builder\ServiceBuilder;
use Guzzle\Service\Client;
use Guzzle\Service\Exception\ServiceNotFoundException;
use Guzzle\Common\Cache\DoctrineCacheAdapter;
use Doctrine\Common\Cache\ArrayCache;

class ServiceBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $arrayData = array(
        'michael.mock' => array(
            'class' => 'Guzzle\\Tests\\Service\\Mock\\MockClient',
            'params' => array(
                'username' => 'michael',
                'password' => 'testing123',
                'subdomain' => 'michael',
            ),
        ),
        'billy.mock' => array(
            'class' => 'Guzzle\\Tests\\Service\\Mock\\MockClient',
            'params' => array(
                'username' => 'billy',
                'password' => 'passw0rd',
                'subdomain' => 'billy',
            ),
        ),
        'billy.testing' => array(
            'extends' => 'billy.mock',
            'params' => array(
                'subdomain' => 'test.billy',
            ),
        ),
        'missing_params' => array(
            'extends' => 'billy.mock'
        )
    );

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::serialize
     * @covers Guzzle\Service\Builder\ServiceBuilder::unserialize
     */
    public function testAllowsSerialization()
    {
        $builder = ServiceBuilder::factory($this->arrayData);
        $cached = unserialize(serialize($builder));
        $this->assertEquals($cached, $builder);
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     */
    public function testDelegatesFactoryMethodToAbstractFactory()
    {
        $builder = ServiceBuilder::factory($this->arrayData);
        $c = $builder->get('michael.mock');
        $this->assertInstanceOf('Guzzle\\Tests\\Service\\Mock\\MockClient', $c);
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::get
     * @expectedException Guzzle\Service\Exception\ServiceNotFoundException
     * @expectedExceptionMessage No service is registered as foobar
     */
    public function testThrowsExceptionWhenGettingInvalidClient()
    {
        ServiceBuilder::factory($this->arrayData)->get('foobar');
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::get
     */
    public function testStoresClientCopy()
    {
        $builder = ServiceBuilder::factory($this->arrayData);
        $client = $builder->get('michael.mock');
        $this->assertInstanceOf('Guzzle\\Tests\\Service\\Mock\\MockClient', $client);
        $this->assertEquals('http://127.0.0.1:8124/v1/michael', $client->getBaseUrl());
        $this->assertEquals($client, $builder->get('michael.mock'));

        // Get another client but throw this one away
        $client2 = $builder->get('billy.mock', true);
        $this->assertInstanceOf('Guzzle\\Tests\\Service\\Mock\\MockClient', $client2);
        $this->assertEquals('http://127.0.0.1:8124/v1/billy', $client2->getBaseUrl());

        // Make sure the original client is still there and set
        $this->assertTrue($client === $builder->get('michael.mock'));

        // Create a new billy.mock client that is stored
        $client3 = $builder->get('billy.mock');

        // Make sure that the stored billy.mock client is equal to the other stored client
        $this->assertTrue($client3 === $builder->get('billy.mock'));

        // Make sure that this client is not equal to the previous throwaway client
        $this->assertFalse($client2 === $builder->get('billy.mock'));
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder
     */
    public function testBuildersPassOptionsThroughToClients()
    {
        $s = new ServiceBuilder(array(
            'michael.mock' => array(
                'class' => 'Guzzle\\Tests\\Service\\Mock\\MockClient',
                'params' => array(
                    'base_url' => 'http://www.test.com/',
                    'subdomain' => 'michael',
                    'password' => 'test',
                    'username' => 'michael',
                    'curl.curlopt_proxyport' => 8080
                )
            )
        ));

        $c = $s->get('michael.mock');
        $this->assertEquals(8080, $c->getConfig('curl.curlopt_proxyport'));
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder
     */
    public function testUsesTheDefaultBuilderWhenNoBuilderIsSpecified()
    {
        $s = new ServiceBuilder(array(
            'michael.mock' => array(
                'class' => 'Guzzle\\Tests\\Service\\Mock\\MockClient',
                'params' => array(
                    'base_url' => 'http://www.test.com/',
                    'subdomain' => 'michael',
                    'password' => 'test',
                    'username' => 'michael',
                    'curl.curlopt_proxyport' => 8080
                )
            )
        ));

        $c = $s->get('michael.mock');
        $this->assertInstanceOf('Guzzle\\Tests\\Service\\Mock\\MockClient', $c);
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::set
     * @covers Guzzle\Service\Builder\ServiceBuilder::offsetSet
     * @covers Guzzle\Service\Builder\ServiceBuilder::offsetGet
     * @covers Guzzle\Service\Builder\ServiceBuilder::offsetUnset
     * @covers Guzzle\Service\Builder\ServiceBuilder::offsetExists
     */
    public function testUsedAsArray()
    {
        $b = ServiceBuilder::factory($this->arrayData);
        $this->assertTrue($b->offsetExists('michael.mock'));
        $this->assertFalse($b->offsetExists('not_there'));
        $this->assertInstanceOf('Guzzle\\Service\\Client', $b['michael.mock']);

        unset($b['michael.mock']);
        $this->assertFalse($b->offsetExists('michael.mock'));

        $b['michael.mock'] = new Client('http://www.test.com/');
        $this->assertInstanceOf('Guzzle\\Service\\Client', $b['michael.mock']);
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     */
    public function testFactoryCanCreateFromJson()
    {
        $tmp = sys_get_temp_dir() . '/test.js';
        file_put_contents($tmp, json_encode($this->arrayData));
        $b = ServiceBuilder::factory($tmp);
        unlink($tmp);
        $s = $b->get('billy.testing');
        $this->assertEquals('test.billy', $s->getConfig('subdomain'));
        $this->assertEquals('billy', $s->getConfig('username'));
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     */
    public function testFactoryCanCreateFromArray()
    {
        $b = ServiceBuilder::factory($this->arrayData);
        $s = $b->get('billy.testing');
        $this->assertEquals('test.billy', $s->getConfig('subdomain'));
        $this->assertEquals('billy', $s->getConfig('username'));
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     * @expectedException Guzzle\Service\Exception\ServiceBuilderException
     * @expectedExceptionMessage Unable to build service builder
     */
    public function testFactoryValidatesFileExtension()
    {
        $tmp = sys_get_temp_dir() . '/test.abc';
        file_put_contents($tmp, 'data');
        try {
            ServiceBuilder::factory($tmp);
        } catch (\RuntimeException $e) {
            unlink($tmp);
            throw $e;
        }
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     * @expectedException Guzzle\Service\Exception\ServiceBuilderException
     * @expectedExceptionMessage Must pass a file name, array, or SimpleXMLElement
     */
    public function testFactoryValidatesObjectTypes()
    {
        ServiceBuilder::factory(new \stdClass());
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     */
    public function testFactoryDoesNotRequireParams()
    {
        $b = ServiceBuilder::factory($this->arrayData);
        $s = $b->get('missing_params');
        $this->assertEquals('billy', $s->getConfig('username'));
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder
     */
    public function testBuilderAllowsReferencesBetweenClients()
    {
        $builder = ServiceBuilder::factory(array(
            'a' => array(
                'class' => 'Guzzle\\Tests\\Service\\Mock\\MockClient',
                'params' => array(
                    'other_client' => '{{ b }}',
                    'username'     => 'x',
                    'password'     => 'y',
                    'subdomain'    => 'z'
                )
            ),
            'b' => array(
                'class' => 'Guzzle\\Tests\\Service\\Mock\\MockClient',
                'params' => array(
                    'username'  => '1',
                    'password'  => '2',
                    'subdomain' => '3'
                )
            )
        ));

        $client = $builder['a'];
        $this->assertEquals('x', $client->getConfig('username'));
        $this->assertSame($builder['b'], $client->getConfig('other_client'));
        $this->assertEquals('1', $builder['b']->getConfig('username'));
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::getAllEvents
     * @covers Guzzle\Service\Builder\ServiceBuilder::get
     */
    public function testEmitsEventsWhenClientsAreCreated()
    {
        // Ensure that the client signals that it emits an event
        $this->assertEquals(array('service_builder.create_client'), ServiceBuilder::getAllEvents());

        // Create a test service builder
        $builder = ServiceBuilder::factory(array(
            'a' => array(
                'class' => 'Guzzle\\Tests\\Service\\Mock\\MockClient',
                'params' => array(
                    'username'  => 'test',
                    'password'  => '123',
                    'subdomain' => 'z'
                )
            )
        ));

        $emits = 0;
        $emitted = null;

        // Add an event listener to pick up client creation events
        $builder->getEventDispatcher()->addListener('service_builder.create_client', function($event) use (&$emits, &$emitted) {
            $emits++;
            $emitted = $event['client'];
        });

        // Get the 'a' client by name
        $client = $builder->get('a');

        // Ensure that the event was emitted once, and that the client was present
        $this->assertEquals(1, $emits);
        $this->assertInstanceOf('Guzzle\Tests\Service\Mock\MockClient', $client);
    }

    /**
     * @covers Guzzle\Service\Builder\ServiceBuilder::factory
     */
    public function testCanAddGlobalParametersToServicesOnLoad()
    {
        $builder = ServiceBuilder::factory($this->arrayData, array(
            'username' => 'fred',
            'new_value' => 'test'
        ));

        $data = json_decode($builder->serialize(), true);

        foreach ($data as $service) {
            $this->assertEquals('fred', $service['params']['username']);
            $this->assertEquals('test', $service['params']['new_value']);
        }
    }

    public function testDescriptionIsCacheable()
    {
        $jsonFile = __DIR__ . '/../../TestData/test_service.json';
        $adapter = new DoctrineCacheAdapter(new ArrayCache());

        $builder = ServiceBuilder::factory($jsonFile, array(
            'cache.adapter' => $adapter
        ));

        // Ensure the cache key was set
        $this->assertTrue($adapter->contains('guzzle' . crc32($jsonFile)));

        // Grab the service from the cache
        $this->assertEquals($builder, ServiceBuilder::factory($jsonFile, array(
            'cache.adapter' => $adapter
        )));
    }
}
