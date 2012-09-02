<?php

namespace Guzzle\Tests\Service\Command;

use Guzzle\Http\Utils;
use Guzzle\Http\Message\PostFile;
use Guzzle\Service\Client;
use Guzzle\Service\Command\DynamicCommand;
use Guzzle\Service\Command\Factory\ServiceDescriptionFactory;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Command\LocationVisitor\HeaderVisitor;

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
        $this->service = new ServiceDescription(array(
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
        ));
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
        $service = new ServiceDescription(array(
            'test_path' => new ApiCommand(array(
                'method' => 'GET',
                'uri'    => '/test',
            ))
        ));

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
        $service = new ServiceDescription(array(
            'test_path' => new ApiCommand(array(
                'method' => 'GET',
                'uri'    => 'test/abc',
            ))
        ));

        $client = new Client('http://www.test.com/api/v2');
        $client->setDescription($service);
        $command = $client->getCommand('test_path');
        $request = $command->prepare();
        $this->assertEquals('/api/v2/test/abc', $request->getPath());
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommand::build
     */
    public function testAllowsPostFieldsAndFiles()
    {
        $service = new ServiceDescription(array(
            'post_command' => new ApiCommand(array(
                'method' => 'POST',
                'uri'    => '/key',
                'params' => array(
                    'test' => array(
                        'location' => 'post_field'
                    ),
                    'test_2' => array(
                        'location' => 'post_field:foo'
                    ),
                    'test_3' => array(
                        'location' => 'post_file'
                    )
                )
            ))
        ));

        $client = new Client('http://www.test.com/api/v2');
        $client->setDescription($service);

        $command = $client->getCommand('post_command', array(
            'test'   => 'Hi!',
            'test_2' => 'There',
            'test_3' => __FILE__
        ));
        $request = $command->prepare();
        $this->assertEquals('Hi!', $request->getPostField('test'));
        $this->assertEquals('There', $request->getPostField('foo'));
        $this->assertInternalType('array', $request->getPostFile('test_3'));

        $command = $client->getCommand('post_command', array(
            'test_3' => new PostFile('baz', __FILE__)
        ));
        $request = $command->prepare();
        $this->assertInternalType('array', $request->getPostFile('baz'));
    }

    /**
     * @covers Guzzle\Service\Command\DynamicCommand::addVisitor
     */
    public function testAllowsCustomVisitor()
    {
        $service = new ServiceDescription(array(
            'foo' => new ApiCommand(array(
                'params' => array(
                    'test' => array(
                        'location' => 'query'
                    )
                )
            ))
        ));
        $client = new Client();
        $client->setDescription($service);

        $command = $client->getCommand('foo', array('test' => 'hi'));
        // Flip query and header
        $command->addVisitor('query', new HeaderVisitor());
        $request = $command->prepare();
        $this->assertEquals('hi', (string) $request->getHeader('test'));
    }
}
