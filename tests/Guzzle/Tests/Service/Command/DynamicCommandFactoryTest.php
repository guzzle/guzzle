<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Command;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Service\Command\DynamicCommandFactory;
use Guzzle\Service\Client;
use Guzzle\Service\ServiceDescription;
use Guzzle\Service\ApiCommand;

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
            'test',
            'Test service',
            'http://{{ bucket }}s3.amazonaws.com{{ key }}',
            array(
                new ApiCommand(array(
                    'name' => 'test_command',
                    'doc' => 'documentationForCommand',
                    'method' => 'HEAD',
                    'can_batch' => true,
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
                ))
            )
        );
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommandFactory
     */
    public function testBuildsUsingPathParametersAndAppendSlashPrepend()
    {
        $factory = new DynamicCommandFactory($this->service);
        $client = new Client($this->service->getBaseUrl());
        $client->setService($this->service);
        $client->setCommandFactory($factory);

        $command = $factory->buildCommand('test_command', array(
            'bucket' => 'test',
            'key' => 'key'
        ));

        $request = $command->prepare($client);

        // Ensure that the path values were injected into the path and base_url
        $this->assertEquals('/key', $request->getPath());
        $this->assertEquals('test.s3.amazonaws.com', $request->getHost());

        // Check the complete request
        $this->assertEquals(
            "HEAD /key HTTP/1.1\r\n" .
            "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n" .
            "Host: test.s3.amazonaws.com\r\n" .
            "\r\n", (string) $request);

        // Make sure the concrete command class is used
        $this->assertEquals(
            'Guzzle\\Service\\Command\\ClosureCommand',
            get_class($command)
        );
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommandFactory
     * @expectedException InvalidArgumentException
     */
    public function testValidatesArgs()
    {
        $factory = new DynamicCommandFactory($this->service);
        $client = new Client($this->service->getBaseUrl());
        $client->setService($this->service)->setCommandFactory($factory);
        $command = $factory->buildCommand('test_command', array());
        $client->execute($command);
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommandFactory
     */
    public function testUsesDifferentLocations()
    {
        $factory = new DynamicCommandFactory($this->service);
        $client = new Client($this->service->getBaseUrl());
        $client->setCommandFactory($factory);
        $command = $factory->buildCommand('body', array(
            'b' => 'my-data',
            'q' => 'abc',
            'h' => 'haha'
        ));

        $request = $command->prepare($client);

        $this->assertEquals(
            "PUT /?test=abc&i=test HTTP/1.1\r\n" .
            "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n" .
            "Host: s3.amazonaws.com\r\n" .
            "X-Custom: haha\r\n" .
            "Content-Length: 29\r\n" .
            "Expect: 100-Continue\r\n" .
            "\r\n" .
            "begin_body::my-data::end_body", (string) $request);

        unset($command);
        unset($request);
        
        $command = $factory->buildCommand('body', array(
            'b' => 'my-data',
            'q' => 'abc',
            'h' => 'haha',
            'i' => 'does not change the value because it\'s static'
        ));

        $request = $command->prepare($client);
        
        $this->assertEquals(
            "PUT /?test=abc&i=test HTTP/1.1\r\n" .
            "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n" .
            "Host: s3.amazonaws.com\r\n" .
            "X-Custom: haha\r\n" .
            "Content-Length: 29\r\n" .
            "Expect: 100-Continue\r\n" .
            "\r\n" .
            "begin_body::my-data::end_body", (string) $request);
    }
}