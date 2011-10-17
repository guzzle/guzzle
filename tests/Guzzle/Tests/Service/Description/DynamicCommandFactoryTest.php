<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Service\Description\DynamicCommandFactory;
use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Description\ApiCommand;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DynamicCommandFactoryTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var ServiceDescription
     */
    protected $service;

    /**
     * Setup the service description
     */
    public function setUp()
    {
        $this->service = new ServiceDescription(
            array(
                new ApiCommand(array(
                    'name' => 'test_command',
                    'doc' => 'documentationForCommand',
                    'method' => 'HEAD',
                    'can_batch' => true,
                    'path' => '/{{key}}',
                    'args' => array(
                        'bucket' => array(
                            'required' => true,
                            'append' => '.',
                            'location' => 'path'
                        ),
                        'key' => array(
                            'location' => 'path',
                            'prepend' => '/'
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
                new ApiCommand(array(
                    'name' => 'body',
                    'doc' => 'doc',
                    'method' => 'PUT',
                    'args' => array(
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
                new ApiCommand(array(
                    'name' => 'concrete',
                    'class' => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
                    'args' => array()
                ))
            )
        );
    }

    /**
     * @covers Guzzle\Service\Description\DynamicCommandFactory
     */
    public function testBuildsUsingPathParametersAndAppendSlashPrepend()
    {
        $client = new Client('http://www.example.com/');
        $client->setDescription($this->service);
        
        $command = $this->service->createCommand('test_command', array(
            'bucket' => 'test',
            'key' => 'key'
        ));
        $request = $command->setClient($client)->prepare();

        // Ensure that the path values were injected into the path and base_url
        $this->assertEquals('/key', $request->getPath());
        $this->assertEquals('www.example.com', $request->getHost());

        // Check the complete request
        $this->assertEquals(
            "HEAD /key HTTP/1.1\r\n" .
            "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n" .
            "Host: www.example.com\r\n" .
            "\r\n", (string) $request);

        // Make sure the concrete command class is used
        $this->assertEquals(
            'Guzzle\\Service\\Command\\ClosureCommand',
            get_class($command)
        );
    }

    /**
     * @covers Guzzle\Service\Description\DynamicCommandFactory
     * @expectedException InvalidArgumentException
     */
    public function testValidatesArgs()
    {
        $client = new Client('http://www.fragilerock.com/');
        $client->setDescription($this->service);
        $command = $this->service->createCommand('test_command', array());
        $client->execute($command);
    }

    /**
     * @covers Guzzle\Service\Description\DynamicCommandFactory
     */
    public function testUsesDifferentLocations()
    {
        $client = new Client('http://www.tazmania.com/');
        $command = $this->service->createCommand('body', array(
            'b' => 'my-data',
            'q' => 'abc',
            'h' => 'haha'
        ));

        $request = $command->setClient($client)->prepare();

        $this->assertEquals(
            "PUT /?test=abc&i=test HTTP/1.1\r\n" .
            "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n" .
            "Host: www.tazmania.com\r\n" .
            "X-Custom: haha\r\n" .
            "Content-Length: 29\r\n" .
            "Expect: 100-Continue\r\n" .
            "\r\n" .
            "begin_body::my-data::end_body", (string) $request);

        unset($command);
        unset($request);
        
        $command = $this->service->createCommand('body', array(
            'b' => 'my-data',
            'q' => 'abc',
            'h' => 'haha',
            'i' => 'does not change the value because it\'s static'
        ));

        $request = $command->setClient($client)->prepare();
        
        $this->assertEquals(
            "PUT /?test=abc&i=test HTTP/1.1\r\n" .
            "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n" .
            "Host: www.tazmania.com\r\n" .
            "X-Custom: haha\r\n" .
            "Content-Length: 29\r\n" .
            "Expect: 100-Continue\r\n" .
            "\r\n" .
            "begin_body::my-data::end_body", (string) $request);
    }

    /**
     * @covers Guzzle\Service\Description\DynamicCommandFactory::createCommand
     */
    public function testBuildsConcreteCommands()
    {
        $c = $this->service->createCommand('concrete');
        $this->assertEquals('Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand', get_class($c));
    }

    /**
     * @covers Guzzle\Service\Description\DynamicCommandFactory::createCommand
     */
    public function testUsesAbsolutePaths()
    {
        $service = new ServiceDescription(
            array(
                new ApiCommand(array(
                    'name' => 'test_path',
                    'method' => 'GET',
                    'path' => '/test',
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
     * @covers Guzzle\Service\Description\DynamicCommandFactory::createCommand
     */
    public function testUsesRelativePaths()
    {
        $service = new ServiceDescription(
            array(
                new ApiCommand(array(
                    'name' => 'test_path',
                    'method' => 'GET',
                    'path' => 'test/abc',
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