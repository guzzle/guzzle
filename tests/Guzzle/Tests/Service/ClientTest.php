<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Common\Log\Logger;
use Guzzle\Http\Plugin\Log\LogPlugin;
use Guzzle\Service\ApiCommand;
use Guzzle\Service\Client;
use Guzzle\Service\Command\ConcreteCommandFactory;
use Guzzle\Service\DescriptionBuilder\XmlDescriptionBuilder;
use Guzzle\Service\ServiceDescription;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ClientTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $factory;
    protected $service;
    protected $serviceTest;
    protected $factoryTest;

    public function setUp()
    {
        $this->serviceTest = new ServiceDescription('test', 'Test service', 'http://www.test.com/', array(
            new ApiCommand(array(
                'name' => 'test_command',
                'doc' => 'documentationForCommand',
                'method' => 'DELETE',
                'can_batch' => true,
                'concrete_command_class' => 'Guzzle\\Service\\Aws\\S3\\Command\\Object\\DeleteObject',
                'args' => array(
                    'bucket' => array(
                        'required' => true
                    ),
                    'key' => array(
                        'required' => true
                    )
                )
            ))
        ), array(
            'foo' => array(
                'default' => 'bar',
                'required' => 'true'
            ),
            'base_url' => array(
                'required' => 'true'
            ),
            'api' => array(
                'required' => 'true'
            )
        ));

        $builder = new XmlDescriptionBuilder(__DIR__ . DIRECTORY_SEPARATOR . 'test_service.xml');
        $this->service = $builder->build();

        $this->factory = new ConcreteCommandFactory($this->service);
    }

    /**
     * Get a LogPlugin
     *
     * @return LogPlugin
     */
    private function getLogPlugin()
    {
        return new \Guzzle\Http\Plugin\Log\LogPlugin(new \Guzzle\Common\Log\Logger(array(new \Guzzle\Common\Log\Adapter\ClosureLogAdapter(
            function($message, $priority, $category, $host) {
                echo $message . ' ' . $priority . ' ' . $category . ' ' . $host . "\n";
            }
        ))));
    }

    /**
     * @covers Guzzle\Service\Client::getConfig
     */
    public function testGetConfig()
    {
        $client = new Client(new Collection(array(
            'base_url' => 'http://www.google.com/'
        )), $this->service, $this->factory);

        $this->assertEquals('http://www.google.com/', $client->getConfig('base_url'));

        $this->assertEquals(array(
            'base_url' => 'http://www.google.com/'
        ), $client->getConfig());
    }

    /**
     * @covers Guzzle\Service\Client::__construct
     * @expectedException Guzzle\Service\ServiceException
     */
    public function testConstructorValidatesConfig()
    {
        $client = new Client(false, $this->service, $this->factory);
    }

    /**
     * @covers Guzzle\Service\Client::__construct
     * @expectedException Guzzle\Service\ServiceException
     */
    public function testConstructorValidatesBaseUrlIsProvided()
    {
        $client = new Client(array(), new ServiceDescription('test', 'Test service', '', array(), array()), $this->factory);
    }

    /**
     * @covers Guzzle\Service\Client::__construct
     */
    public function testCanUseCollectionAsConfig()
    {
        $client = new Client(new Collection(array(
            'api' => 'v1',
            'key' => 'value',
            'base_url' => 'http://www.google.com/'
        )), $this->serviceTest, $this->factory);
        $this->assertEquals('v1', $client->getConfig('api'));
    }

    /**
     * @covers Guzzle\Service\Client
     */
    public function testInjectConfig()
    {
        $client = new Client(array(
            'api' => 'v1',
            'key' => 'value',
            'base_url' => 'http://www.google.com/'
        ), $this->serviceTest, $this->factory);

        $this->assertEquals('Testing...api/v1/key/value', $client->injectConfig('Testing...api/{{ api }}/key/{{ key }}'));

        // Make sure that the client properly validates and injects config
        $this->assertEquals('bar', $client->getConfig('foo'));

        try {
            $client = new Client(array(), $this->serviceTest, $this->factory);
            $this->fail('Did not throw exception when missing required arg');
        } catch (\Exception $e) {
            $this->assertContains('Requires that the api argument be supplied', $e->getMessage());
        }
    }

    /**
     * Test that a plugin can be attached to a client
     *
     * @covers Guzzle\Service\Client::__construct
     * @covers Guzzle\Service\Client::setRequestFactory
     * @covers Guzzle\Service\Client::attachPlugin
     * @covers Guzzle\Service\Client::hasPlugin
     * @covers Guzzle\Service\Client::getPlugin
     * @covers Guzzle\Service\Client::getShortPluginName
     * @covers Guzzle\Service\Client::detachPlugin
     * @covers Guzzle\Service\Client::getRequest
     * @covers Guzzle\Http\Plugin\AbstractPlugin
     * @covers Guzzle\Http\Plugin\Log\LogPlugin
     */
    public function testClientHandlesPlugins()
    {
        $client = new Client(array(
            'base_url' => 'http://www.google.com/'
        ), $this->service, $this->factory);

        $logPlugin = $this->getLogPlugin();

        $client->attachPlugin($logPlugin);

        // Make sure the plugin was registered correctly
        $this->assertTrue($client->hasPlugin($logPlugin));
        $this->assertTrue($client->hasPlugin('LogPlugin'));
        $this->assertFalse($client->hasPlugin('test'));

        // Make sure that we can get the log plugin by long or short name
        $this->assertSame($logPlugin, $client->getPlugin('Guzzle\Http\Plugin\Log\LogPlugin'));
        $this->assertSame($logPlugin, $client->getPlugin('LogPlugin'));

        // Create a new filter that will set a mock response on all requests
        $filter = new \Guzzle\Tests\Common\Mock\MockFilter(array(
            'callback' => function($filter, $context) {
                $context->setResponse(new \Guzzle\Http\Message\Response(200), true);
            }
        ));

        // Add the filter to the request creation chain
        $client->getCreateRequestChain()->addFilter($filter);

        $request = $client->getRequest('GET');
        // Make sure the the plugin was attached to the new request
        $this->assertTrue($request->getPrepareChain()->hasFilter($logPlugin));
        $this->assertTrue($request->getProcessChain()->hasFilter($logPlugin));
        $this->assertTrue($request->getSubjectMediator()->hasObserver($logPlugin));

        // Make sure that the log plugin actually logged the request and response
        ob_start();
        $request->send();
        $logged = ob_get_clean();
        $this->assertEquals('www.google.com - "GET / HTTP/1.1" - 200 0 - 7 guzzle_request ' . gethostname() . "\n", $logged);

        // Detach the log plugin
        $client->detachPlugin($logPlugin);

        // Ensure that it was actually detached
        $this->assertFalse($client->hasPlugin($logPlugin));
        $request = $client->getRequest('GET');
        $this->assertFalse($request->getPrepareChain()->hasFilter($logPlugin));
        $this->assertFalse($request->getProcessChain()->hasFilter($logPlugin));
        $this->assertFalse($request->getSubjectMediator()->hasObserver($logPlugin));

        // Try that again, but reference it by name
        $client->attachPlugin($logPlugin);
        $this->assertTrue($client->hasPlugin($logPlugin));

        // Try detaching a non-existent plugin
        $client->detachPlugin('SuperLogPlugin');
        $this->assertTrue($client->hasPlugin($logPlugin));

        // Now really detach the log plugin
        $client->detachPlugin('LogPlugin');
        $this->assertFalse($client->hasPlugin($logPlugin));
    }

    /**
     * @covers Guzzle\Service\Client::getBaseUrl
     * @covers Guzzle\Service\Client::setBaseUrl
     */
    public function testClientReturnsValidBaseUrls()
    {
        $client = new Client(array(
            'base_url' => 'http://www.{{ foo }}.{{ data }}/',
            'data' => '123',
            'foo' => 'bar'
        ), $this->service, $this->factory);

        $this->assertEquals('http://www.bar.123/', $client->getBaseUrl());
        $client->setBaseUrl('http://www.google.com/');
        $this->assertEquals('http://www.google.com/', $client->getBaseUrl());
    }

    /**
     * @covers Guzzle\Service\Client::execute
     * @expectedException Guzzle\Service\Command\CommandSetException
     */
    public function testThrowsExceptionWhenExecutingMixedClientCommandSets()
    {
        $client = new Client(array('base_url' => 'http://www.test.com/'), $this->service, $this->factory);
        $otherClient = new Client(array('base_url' => 'http://www.test-123.com/'), $this->service, $this->factory);

        // Create a command set and a command
        $set = new \Guzzle\Service\Command\CommandSet();
        $cmd = new Command\MockCommand();
        $set->addCommand($cmd);

        // Associate the other client with the command
        $cmd->setClient($otherClient);

        // Send the set with the wrong client, causing an exception
        $client->execute($set);
    }

    /**
     * @covers Guzzle\Service\Client::execute
     * @expectedException Guzzle\Service\ServiceException
     */
    public function testThrowsExceptionWhenExecutingInvalidCommandSets()
    {
        $client = new Client(array('base_url' => 'http://www.test.com/'), $this->service, $this->factory);
        $client->execute(new \stdClass());
    }

    /**
     * @covers Guzzle\Service\Client::execute
     * @covers Guzzle\Service\Client::getCreateRequestChain
     */
    public function testExecutesCommandSets()
    {
        $client = new Client(array('base_url' => 'http://www.test.com/'), $this->service, $this->factory);

        // Set a mock response for each request from the Client
        $client->getCreateRequestChain()->addFilter(new \Guzzle\Tests\Common\Mock\MockFilter(array(
            'callback' => function($filter, $command) {
                $command->setResponse(new \Guzzle\Http\Message\Response(200));
            }
        )));

        // Create a command set and a command
        $set = new \Guzzle\Service\Command\CommandSet();
        $cmd = new Command\MockCommand();
        $set->addCommand($cmd);
        $this->assertSame($set, $client->execute($set));

        // Make sure it sent
        $this->assertTrue($cmd->isExecuted());
        $this->assertTrue($cmd->isPrepared());
        $this->assertEquals(200, $cmd->getResponse()->getStatusCode());
    }

    /**
     * @covers Guzzle\Service\Client::getCommand
     */
    public function testClientUsesCommandFactory()
    {
        $client = new Client(
            array('base_url' => 'http://www.test.com/', 'api' => 'v1'),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $this->assertInstanceOf('Guzzle\\Service\\Command\\CommandInterface', $client->getCommand('test_command', array(
            'bucket' => 'test',
            'key' => 'keyTest'
        )));
    }

    /**
     * @covers Guzzle\Service\Client::getService
     */
    public function testClientUsesService()
    {
        $client = new Client(
            array('base_url' => 'http://www.test.com/', 'api' => 'v1'),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $this->assertInstanceOf('Guzzle\\Service\\ServiceDescription', $client->getService());
        $this->assertSame($this->serviceTest, $client->getService());
    }

    /**
     * @covers Guzzle\Service\Client::setUserApplication
     * @covers Guzzle\Service\Client::getRequest
     */
    public function testSetsUserApplication()
    {
        $client = new Client(
            array('base_url' => 'http://www.test.com/', 'api' => 'v1'),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $this->assertSame($client, $client->setUserApplication('Test', '1.0Ab'));

        $request = $client->getRequest('GET');
        $this->assertEquals('Test/1.0Ab ' . Guzzle::getDefaultUserAgent(), $request->getHeader('User-Agent'));
    }

    /**
     * @covers Guzzle\Service\Client::getRequest
     */
    public function testClientAddsCurlOptionsToRequests()
    {
        $client = new Client(
            array(
                'base_url' => 'http://www.test.com/',
                'api' => 'v1',
                // Adds the option using the curl values
                'curl.CURLOPT_HTTPAUTH' => 'CURLAUTH_DIGEST',
                'curl.abc' => 'not added'
            ),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $request = $client->getRequest('GET');
        $options = $request->getCurlOptions();        
        $this->assertEquals(CURLAUTH_DIGEST, $options->get(CURLOPT_HTTPAUTH));
        $this->assertNull($options->get('curl.abc'));
    }

    /**
     * @covers Guzzle\Service\Client::getRequest
     */
    public function testClientAddsCacheKeyFiltersToRequests()
    {
        $client = new Client(
            array(
                'base_url' => 'http://www.test.com/',
                'api' => 'v1',
                'cache.key_filter' => 'query=Date'
            ),
            $this->serviceTest,
            new ConcreteCommandFactory($this->serviceTest)
        );

        $request = $client->getRequest('GET');
        $this->assertEquals('query=Date', $request->getParams()->get('cache.key_filter'));
    }
}