<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Common\Collection;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Client;

class ServiceDescriptionTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $serviceData;

    public function setup()
    {
        $this->serviceData = array(
            'test_command' => new ApiCommand(array(
                'doc' => 'documentationForCommand',
                'method' => 'DELETE',
                'class' => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
                'params' => array(
                    'bucket' => array(
                        'required' => true
                    ),
                    'key' => array(
                        'required' => true
                    )
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
        $xmlFile = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.xml';
        $jsonFile = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.json';
        $this->assertInstanceOf('Guzzle\Service\Description\ServiceDescription', ServiceDescription::factory($xmlFile));
        $this->assertInstanceOf('Guzzle\Service\Description\ServiceDescription', ServiceDescription::factory($jsonFile));
    }

    /**
     * @covers Guzzle\Service\Description\ServiceDescription
     * @covers Guzzle\Service\Description\ServiceDescription::__construct
     * @covers Guzzle\Service\Description\ServiceDescription::getCommands
     * @covers Guzzle\Service\Description\ServiceDescription::getCommand
     */
    public function testConstructor()
    {
        $service = new ServiceDescription($this->serviceData);

        $this->assertEquals(1, count($service->getCommands()));
        $this->assertFalse($service->hasCommand('foobar'));
        $this->assertTrue($service->hasCommand('test_command'));
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
        $this->assertEquals($service, $d2);
    }

    public function testAllowsForJsonBasedArrayParamsFunctionalTest()
    {
        $service = array(
            'test' => new ApiCommand(array(
                'method' => 'PUT',
                'params' => array(
                    'data'   => array(
                        'required' => true,
                        'type'     => 'type:array',
                        'filters'  => 'json_encode',
                        'location' => 'body'
                    )
                )
            ))
        );

        $description = new ServiceDescription($service);
        $client = new Client();
        $client->setDescription($description);
        $command = $client->getCommand('test', array(
            'data' => array(
                'foo' => 'bar'
            )
        ));

        $request = $command->prepare();
        $this->assertEquals(json_encode(array(
            'foo' => 'bar'
        )), (string) $request->getBody());
    }
}
