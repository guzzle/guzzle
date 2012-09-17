<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\Operation;
use Guzzle\Service\Client;

class ServiceDescriptionTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $serviceData;

    public function setup()
    {
        $this->serviceData = array(
            'test_command' => new Operation(array(
                'name'        => 'test_command',
                'description' => 'documentationForCommand',
                'httpMethod'  => 'DELETE',
                'class'       => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
                'parameters'  => array(
                    'bucket'  => array('required' => true),
                    'key'     => array('required' => true)
                )
            ))
        );
    }

    /**
     * @covers Guzzle\Service\Description\ServiceDescription::factory
     * @covers Guzzle\Service\Description\ArrayDescriptionBuilder::build
     */
    public function testFactoryDelegatesToConcreteFactories()
    {
        $jsonFile = __DIR__ . '/../../TestData/test_service.json';
        $this->assertInstanceOf('Guzzle\Service\Description\ServiceDescription', ServiceDescription::factory($jsonFile));
    }

    /**
     * @covers Guzzle\Service\Description\ServiceDescription
     * @covers Guzzle\Service\Description\ServiceDescription::__construct
     * @covers Guzzle\Service\Description\ServiceDescription::getOperations
     * @covers Guzzle\Service\Description\ServiceDescription::getOperation
     */
    public function testConstructor()
    {
        $service = new ServiceDescription(array('operations' => $this->serviceData));
        $this->assertEquals(1, count($service->getOperations()));
        $this->assertFalse($service->hasOperation('foobar'));
        $this->assertTrue($service->hasOperation('test_command'));
    }

    /**
     * @covers Guzzle\Service\Description\ServiceDescription::serialize
     * @covers Guzzle\Service\Description\ServiceDescription::unserialize
     */
    public function testIsSerializable()
    {
        $service = new ServiceDescription($this->serviceData);
        $data = serialize($service);
        $d2 = unserialize($data);
        $this->assertEquals(serialize($service), serialize($d2));
    }

    public function testSerializesParameters()
    {
        $service = new ServiceDescription(array(
            'operations' => array(
                'foo' => new Operation(array('parameters' => array('foo' => array('type' => 'string'))))
            )
        ));
        $serialized = serialize($service);
        $this->assertContains('"parameters":{"foo":', $serialized);
        $service = unserialize($serialized);
        $this->assertTrue($service->getOperation('foo')->hasParam('foo'));
    }

    public function testAllowsForJsonBasedArrayParamsFunctionalTest()
    {
        $service = array(
            'test' => new Operation(array(
                'httpMethod' => 'PUT',
                'parameters' => array(
                    'data'   => array(
                        'required' => true,
                        'type'     => 'array',
                        'filters'  => 'json_encode',
                        'location' => 'body'
                    )
                )
            ))
        );

        $description = new ServiceDescription(array('operations' => $service));
        $client = new Client();
        $client->setDescription($description);
        $command = $client->getCommand('test', array('data' => array('foo' => 'bar')));

        $request = $command->prepare();
        $this->assertEquals(json_encode(array('foo' => 'bar')), (string) $request->getBody());
    }

    public function testContainsModels()
    {
        $d = new ServiceDescription(array(
            'operations' => array('foo' => array()),
            'models' => array(
                'Tag'    => array('type' => 'object'),
                'Person' => array('type' => 'object')
            )
        ));
        $this->assertTrue($d->hasModel('Tag'));
        $this->assertTrue($d->hasModel('Person'));
        $this->assertFalse($d->hasModel('Foo'));
        $this->assertInstanceOf('Guzzle\Service\Description\Parameter', $d->getModel('Tag'));
        $this->assertNull($d->getModel('Foo'));
        $this->assertContains('"models":{', serialize($d));
    }

    public function testHasAttributes()
    {
        $d = new ServiceDescription(array(
            'operations'  => array(),
            'name'        => 'Name',
            'description' => 'Description',
            'apiVersion'  => '1.24'
        ));

        $this->assertEquals('Name', $d->getName());
        $this->assertEquals('Description', $d->getDescription());
        $this->assertEquals('1.24', $d->getApiVersion());

        $s = serialize($d);
        $this->assertContains('"name":"Name"', $s);
        $this->assertContains('"description":"Description"', $s);
        $this->assertContains('"apiVersion":"1.24"', $s);

        $d = unserialize($s);
        $this->assertEquals('Name', $d->getName());
        $this->assertEquals('Description', $d->getDescription());
        $this->assertEquals('1.24', $d->getApiVersion());
    }
}
