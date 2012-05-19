<?php

namespace Guzzle\Tests\Service\Command;

use Guzzle\Common\Collection;
use Guzzle\Http\Utils;
use Guzzle\Service\Client;
use Guzzle\Service\Command\DynamicCommand;
use Guzzle\Service\Command\Factory\ServiceDescriptionFactory;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Description\ApiCommandFactory;
use Guzzle\Service\Description\ServiceDescription;

class DynamicCommandTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var ServiceDescription
     */
    protected $service;

    /**
     * @var ServiceDescriptionFactory
     */
    protected $factory;

    /**
     * Setup the service description
     */
    public function setUp()
    {
        $this->service = new ServiceDescription(
            array(
                'test_command' => new ApiCommand(array(
                    'doc' => 'documentationForCommand',
                    'method' => 'HEAD',
                    'uri'    => '{/key}',
                    'params' => array(
                        'bucket' => array(
                            'required' => true,
                            'append' => '.'
                        ),
                        'key' => array(
                            'prepend' => 'hi_'
                        ),
                        'acl' => array(
                            'location' => 'query'
                        ),
                        'meta' => array(
                            'location' => 'header:X-Amz-Meta',
                            'append' => ':meta'
                        )
                    )
                )),
                'body' => new ApiCommand(array(
                    'doc' => 'doc',
                    'method' => 'PUT',
                    'params' => array(
                        'b' => array(
                            'required' => true,
                            'prepend' => 'begin_body::',
                            'append' => '::end_body',
                            'location' => 'body'
                        ),
                        'q' => array(
                            'location' => 'query:test'
                        ),
                        'h' => array(
                            'location' => 'header:X-Custom'
                        ),
                        'i' => array(
                            'static' => 'test',
                            'location' => 'query'
                        ),
                        // Data locations means the argument is just a placeholder for data
                        // that can be referenced by other arguments
                        'data' => array(
                            'location' => 'data'
                        )
                    )
                )),
                'concrete' => new ApiCommand(array(
                    'class' => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
                    'params' => array()
                ))
            )
        );
        $this->factory = new ServiceDescriptionFactory($this->service);
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommand
     */
    public function testBuildsUsingPathParametersAndAppendSlashPrepend()
    {
        $client = new Client('http://www.example.com/');
        $client->setDescription($this->service);

        $command = $this->factory->factory('test_command', array(
            'bucket' => 'test',
            'key' => 'key'
        ));
        $request = $command->setClient($client)->prepare();

        // Ensure that the path values were injected into the path and base_url
        $this->assertEquals('/hi_key', $request->getPath());
        $this->assertEquals('www.example.com', $request->getHost());

        // Check the complete request
        $this->assertEquals(
            "HEAD /hi_key HTTP/1.1\r\n" .
            "Host: www.example.com\r\n" .
            "User-Agent: " . Utils::getDefaultUserAgent() . "\r\n" .
            "\r\n", (string) $request);
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommand
     * @expectedException Guzzle\Service\Exception\ValidationException
     */
    public function testValidatesArgs()
    {
        $client = new Client('http://www.fragilerock.com/');
        $client->setDescription($this->service);
        $command = $this->factory->factory('test_command', array());
        $client->execute($command);
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommand
     */
    public function testUsesDifferentLocations()
    {
        $client = new Client('http://www.tazmania.com/');
        $command = $this->factory->factory('body', array(
            'b' => 'my-data',
            'q' => 'abc',
            'h' => 'haha'
        ));

        $request = $command->setClient($client)->prepare();

        $this->assertEquals(
            "PUT /?test=abc&i=test HTTP/1.1\r\n" .
            "Host: www.tazmania.com\r\n" .
            "User-Agent: " . Utils::getDefaultUserAgent() . "\r\n" .
            "Expect: 100-Continue\r\n" .
            "Content-Length: 29\r\n" .
            "X-Custom: haha\r\n" .
            "\r\n" .
            "begin_body::my-data::end_body", (string) $request);

        unset($command);
        unset($request);

        $command = $this->factory->factory('body', array(
            'b' => 'my-data',
            'q' => 'abc',
            'h' => 'haha',
            'i' => 'does not change the value because it\'s static'
        ));

        $request = $command->setClient($client)->prepare();

        $this->assertEquals(
            "PUT /?test=abc&i=test HTTP/1.1\r\n" .
            "Host: www.tazmania.com\r\n" .
            "User-Agent: " . Utils::getDefaultUserAgent() . "\r\n" .
            "Expect: 100-Continue\r\n" .
            "Content-Length: 29\r\n" .
            "X-Custom: haha\r\n" .
            "\r\n" .
            "begin_body::my-data::end_body", (string) $request);
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommand::build
     */
    public function testBuildsConcreteCommands()
    {
        $c = $this->factory->factory('concrete');
        $this->assertEquals('Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand', get_class($c));
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommand::build
     */
    public function testUsesAbsolutePaths()
    {
        $service = new ServiceDescription(
            array(
                'test_path' => new ApiCommand(array(
                    'method' => 'GET',
                    'uri'    => '/test',
                ))
            )
        );

        $client = new Client('http://www.test.com/');
        $client->setDescription($service);
        $command = $client->getCommand('test_path');
        $request = $command->prepare();
        $this->assertEquals('/test', $request->getPath());
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommand::build
     */
    public function testUsesRelativePaths()
    {
        $service = new ServiceDescription(
            array(
                'test_path' => new ApiCommand(array(
                    'method' => 'GET',
                    'uri'    => 'test/abc',
                ))
            )
        );

        $client = new Client('http://www.test.com/api/v2');
        $client->setDescription($service);
        $command = $client->getCommand('test_path');
        $request = $command->prepare();
        $this->assertEquals('/api/v2/test/abc', $request->getPath());
    }
}
