<?php

namespace Guzzle\Tests\Service;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Common\Log\ClosureLogAdapter;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Plugin\ExponentialBackoffPlugin;
use Guzzle\Http\Plugin\LogPlugin;
use Guzzle\Http\Pool\Pool;
use Guzzle\Service\Description\ApiCommand;
use Guzzle\Service\Client;
use Guzzle\Service\Command\CommandSet;
use Guzzle\Service\Command\CommandInterface;
use Guzzle\Service\Description\XmlDescriptionBuilder;
use Guzzle\Service\Description\ServiceDescription;
use Guzzle\Service\Plugin\MockPlugin;
use Guzzle\Tests\Service\Mock\Command\MockCommand;

/**
 * @group server
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
        $this->serviceTest = new ServiceDescription(array(
            new ApiCommand(array(
                'name' => 'test_command',
                'doc' => 'documentationForCommand',
                'method' => 'DELETE',
                'can_batch' => true,
                'class' => 'Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand',
                'args' => array(
                    'bucket' => array(
                        'required' => true
                    ),
                    'key' => array(
                        'required' => true
                    )
                )
            ))
        ));

        $builder = new XmlDescriptionBuilder(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.xml');
        $this->service = $builder->build();
    }

    /**
     * Get a LogPlugin
     *
     * @return LogPlugin
     */
    private function getLogPlugin()
    {
        return new LogPlugin(new ClosureLogAdapter(
            function($message, $priority, $extras = null) {
                echo $message . ' ' . $priority . ' ' . implode(' - ', (array) $extras) . "\n";
            }
        ));
    }

    /**
     * @covers Guzzle\Service\Client::getConfig
     * @covers Guzzle\Service\Client::setConfig
     * @covers Guzzle\Service\Client::setBaseUrl
     * @covers Guzzle\Service\Client::getBaseUrl
     */
    public function testAcceptsConfig()
    {
        $client = new Client('http://www.google.com/');
        $this->assertEquals('http://www.google.com/', $client->getBaseUrl());
        $this->assertSame($client, $client->setConfig(array(
            'test' => '123'
        )));
        $this->assertEquals(array('test' => '123'), $client->getConfig()->getAll());
        $this->assertEquals('123', $client->getConfig('test'));
        $this->assertSame($client, $client->setBaseUrl('http://www.test.com/{{test}}'));
        $this->assertEquals('http://www.test.com/123', $client->getBaseUrl());
        $this->assertEquals('http://www.test.com/{{test}}', $client->getBaseUrl(false));

        try {
            $client->setConfig(false);
        } catch (\InvalidArgumentException $e) {
        }
    }

    /**
     * @covers Guzzle\Service\Client::__construct
     */
    public function testConstructorCanAcceptConfig()
    {
        $client = new Client('http://www.test.com/', array(
            'data' => '123'
        ));
        $this->assertEquals('123', $client->getConfig('data'));
    }

    /**
     * @covers Guzzle\Service\Client::setConfig
     */
    public function testCanUseCollectionAsConfig()
    {
        $client = new Client('http://www.google.com/');
        $client->setConfig(new Collection(array(
            'api' => 'v1',
            'key' => 'value',
            'base_url' => 'http://www.google.com/'
        )));
        $this->assertEquals('v1', $client->getConfig('api'));
    }

    /**
     * @covers Guzzle\Service\Client::factory
     */
    public function testFactoryCreatesClient()
    {
        $client = Client::factory(array(
            'base_url' => 'http://www.test.com/',
            'test' => '123'
        ));

        $this->assertEquals('http://www.test.com/', $client->getBaseUrl());
        $this->assertEquals('123', $client->getConfig('test'));
    }

    /**
     * @covers Guzzle\Service\Client
     */
    public function testInjectConfig()
    {
        $client = new Client('http://www.google.com/');
        $client->setConfig(array(
            'api' => 'v1',
            'key' => 'value',
            'foo' => 'bar'
        ));
        $this->assertEquals('Testing...api/v1/key/value', $client->inject('Testing...api/{{api}}/key/{{key}}'));

        // Make sure that the client properly validates and injects config
        $this->assertEquals('bar', $client->getConfig('foo'));
    }

    /**
     * @covers Guzzle\Service\Client::__construct
     * @covers Guzzle\Service\Client::createRequest
     */
    public function testClientAttachersObserversToRequests()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        $client = new Client($this->getServer()->getUrl());
        $logPlugin = $this->getLogPlugin();
        $client->getEventManager()->attach($logPlugin);

        // Make sure the plugin was registered correctly
        $this->assertTrue($client->getEventManager()->hasObserver($logPlugin));

        // Get a request from the client and ensure the the observer was
        // attached to the new request
        $request = $client->createRequest();
        $this->assertTrue($request->getEventManager()->hasObserver($logPlugin));

        // Make sure that the log plugin actually logged the request and response
        ob_start();
        $request->send();
        $logged = ob_get_clean();
        $this->assertContains('"GET / HTTP/1.1" - 200', $logged);
    }

    /**
     * @covers Guzzle\Service\Client::getBaseUrl
     * @covers Guzzle\Service\Client::setBaseUrl
     */
    public function testClientReturnsValidBaseUrls()
    {
        $client = new Client('http://www.{{foo}}.{{data}}/', array(
            'data' => '123',
            'foo' => 'bar'
        ));
        $this->assertEquals('http://www.bar.123/', $client->getBaseUrl());
        $client->setBaseUrl('http://www.google.com/');
        $this->assertEquals('http://www.google.com/', $client->getBaseUrl());
    }

    /**
     * @covers Guzzle\Service\Client::execute
     */
    public function testExecutesCommands()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        $client = new Client($this->getServer()->getUrl());
        $cmd = new MockCommand();
        $client->execute($cmd);

        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $cmd->getResponse());
        $this->assertInstanceOf('Guzzle\\Http\\Message\\Response', $cmd->getResult());
        $this->assertEquals(1, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Service\Client::execute
     */
    public function testExecutesCommandsWithArray()
    {
        $client = new Client('http://www.test.com/');
        $client->getEventManager()->attach(new MockPlugin(array(
            new \Guzzle\Http\Message\Response(200),
            new \Guzzle\Http\Message\Response(200)
        )));

        // Create a command set and a command
        $set = array(new MockCommand(), new MockCommand());
        $client->execute($set);

        // Make sure it sent
        $this->assertTrue($set[0]->isExecuted());
        $this->assertTrue($set[1]->isExecuted());
    }

    /**
     * @covers Guzzle\Service\Client::execute
     * @expectedException Guzzle\Service\Command\CommandSetException
     */
    public function testThrowsExceptionWhenExecutingMixedClientCommandSets()
    {
        $client = new Client('http://www.test.com/');
        $otherClient = new Client('http://www.test-123.com/');

        // Create a command set and a command
        $set = new CommandSet();
        $cmd = new MockCommand();
        $set->addCommand($cmd);

        // Associate the other client with the command
        $cmd->setClient($otherClient);

        // Send the set with the wrong client, causing an exception
        $client->execute($set);
    }

    /**
     * @covers Guzzle\Service\Client::execute
     * @expectedException InvalidArgumentException
     */
    public function testThrowsExceptionWhenExecutingInvalidCommandSets()
    {
        $client = new Client('http://www.test.com/');
        $client->execute(new \stdClass());
    }

    /**
     * @covers Guzzle\Service\Client::execute
     */
    public function testExecutesCommandSets()
    {
        $client = new Client('http://www.test.com/');
        $client->getEventManager()->attach(new MockPlugin(array(
            new \Guzzle\Http\Message\Response(200)
        )));

        // Create a command set and a command
        $set = new CommandSet();
        $cmd = new MockCommand();
        $set->addCommand($cmd);
        $this->assertSame($set, $client->execute($set));

        // Make sure it sent
        $this->assertTrue($cmd->isExecuted());
        $this->assertTrue($cmd->isPrepared());
        $this->assertEquals(200, $cmd->getResponse()->getStatusCode());
    }

    /**
     * @covers Guzzle\Service\Client::setUserApplication
     * @covers Guzzle\Service\Client::createRequest
     * @covers Guzzle\Service\Client::prepareRequest
     */
    public function testSetsUserApplication()
    {
        $client = new Client('http://www.test.com/', array(
            'api' => 'v1'
        ));

        $this->assertSame($client, $client->setUserApplication('Test', '1.0Ab'));
        $request = $client->get();
        $this->assertEquals('Test/1.0Ab ' . Guzzle::getDefaultUserAgent(), $request->getHeader('User-Agent'));
    }

    /**
     * @covers Guzzle\Service\Client::createRequest
     * @covers Guzzle\Service\Client::prepareRequest
     */
    public function testClientAddsCurlOptionsToRequests()
    {
        $client = new Client('http://www.test.com/', array(
            'api' => 'v1',
            // Adds the option using the curl values
            'curl.CURLOPT_HTTPAUTH' => 'CURLAUTH_DIGEST',
            'curl.abc' => 'not added'
        ));

        $request = $client->createRequest();
        $options = $request->getCurlOptions();
        $this->assertEquals(CURLAUTH_DIGEST, $options->get(CURLOPT_HTTPAUTH));
        $this->assertNull($options->get('curl.abc'));
    }

    /**
     * @covers Guzzle\Service\Client::createRequest
     * @covers Guzzle\Service\Client::prepareRequest
     */
    public function testClientAddsCacheKeyFiltersToRequests()
    {
        $client = new Client('http://www.test.com/', array(
            'api' => 'v1',
            'cache.key_filter' => 'query=Date'
        ));

        $request = $client->createRequest();
        $this->assertEquals('query=Date', $request->getParams()->get('cache.key_filter'));
    }

    /**
     * @covers Guzzle\Service\Client::getCommand
     * @expectedException InvalidArgumentException
     */
    public function testThrowsExceptionWhenNoCommandFactoryIsSetAndGettingCommand()
    {
        $client = new Client($this->getServer()->getUrl());
        $client->getCommand('test');
    }

    /**
     * @covers Guzzle\Service\Client::getCommand
     * @covers Guzzle\Service\Client::getDescription
     * @covers Guzzle\Service\Client::setDescription
     */
    public function testRetrievesCommandsFromConcreteAndService()
    {
        $client = new Mock\MockClient('http://www.example.com/');
        $this->assertSame($client, $client->setDescription($this->serviceTest));
        $this->assertSame($this->serviceTest, $client->getDescription());
        // Creates service commands
        $this->assertInstanceOf('Guzzle\\Tests\\Service\\Mock\\Command\\MockCommand', $client->getCommand('test_command'));
        // Creates concrete commands
        $this->assertInstanceOf('Guzzle\\Tests\\Service\\Mock\\Command\\OtherCommand', $client->getCommand('other_command'));
    }

    /**
     * @covers Guzzle\Service\Client::prepareRequest
     */
    public function testPreparesRequestsNotCreatedByTheClient()
    {
        $client = new Client($this->getServer()->getUrl());
        $client->getEventManager()->attach(new ExponentialBackoffPlugin());
        $request = RequestFactory::get($client->getBaseUrl());
        $this->assertSame($request, $client->prepareRequest($request));
        $this->assertTrue($request->getEventManager()->hasObserver('Guzzle\\Http\\Plugin\\ExponentialBackoffPlugin'));
    }

    /**
     * @covers Guzzle\Service\Client::createRequest
     */
    public function testCreatesRequestsWithDefaultValues()
    {
        $client = new Client($this->getServer()->getUrl() . 'base');

        // Create a GET request
        $request = $client->createRequest();
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals($client->getBaseUrl(), $request->getUrl());

        // Create a DELETE request
        $request = $client->createRequest('DELETE');
        $this->assertEquals('DELETE', $request->getMethod());
        $this->assertEquals($client->getBaseUrl(), $request->getUrl());

        // Create a HEAD request with custom headers
        $request = $client->createRequest('HEAD', 'http://www.test.com/');
        $this->assertEquals('HEAD', $request->getMethod());
        $this->assertEquals('http://www.test.com/', $request->getUrl());

        // Create a PUT request
        $request = $client->createRequest('PUT');
        $this->assertEquals('PUT', $request->getMethod());

        // Create a PUT request with injected config
        $client->getConfig()->set('a', 1)->set('b', 2);
        $request = $client->createRequest('PUT', '/path/{{a}}?q={{b}}');
        $this->assertEquals($request->getUrl(), $this->getServer()->getUrl() . 'path/1?q=2');

        // Realtive URL with relative path
        $request = $client->createRequest('GET', 'relative/path/to/resource');
        $this->assertEquals($this->getServer()->getUrl() . 'base/relative/path/to/resource', $request->getUrl());

        // Realtive URL with relative path and query
        $request = $client->createRequest('GET', 'relative/path/to/resource?a=b&c=d');
        $this->assertEquals($this->getServer()->getUrl() . 'base/relative/path/to/resource?a=b&c=d', $request->getUrl());

        // Relative URL with absolute path
        $request = $client->createRequest('GET', '/absolute/path/to/resource');
        $this->assertEquals($this->getServer()->getUrl() . 'absolute/path/to/resource', $request->getUrl());

        // Relative URL with absolute path and query
        $request = $client->createRequest('GET', '/absolute/path/to/resource?a=b&c=d');
        $this->assertEquals($this->getServer()->getUrl() . 'absolute/path/to/resource?a=b&c=d', $request->getUrl());

        // Test with a base URL containing a query string too
        $client = new Client($this->getServer()->getUrl() . 'base?z=1');

        // Absolute so replaces query
        $request = $client->createRequest('GET', '/absolute/path/to/resource?a=b&c=d');
        $this->assertEquals($this->getServer()->getUrl() . 'absolute/path/to/resource?a=b&c=d', $request->getUrl());

        // Add relative with no query
        $request = $client->createRequest('GET', 'relative/path/to/resource');
        $this->assertEquals($this->getServer()->getUrl() . 'base/relative/path/to/resource?z=1', $request->getUrl());

        // Add relative with query
        $request = $client->createRequest('GET', 'relative/path/to/resource?another=query');
        $this->assertEquals($this->getServer()->getUrl() . 'base/relative/path/to/resource?z=1&another=query', $request->getUrl());
    }

    /**
     * @covers Guzzle\Service\Client::get
     * @covers Guzzle\Service\Client::delete
     * @covers Guzzle\Service\Client::head
     * @covers Guzzle\Service\Client::put
     * @covers Guzzle\Service\Client::post
     * @covers Guzzle\Service\Client::options
     */
    public function testClientHasHelperMethodsForCreatingRequests()
    {
        $url = $this->getServer()->getUrl();
        $client = new Client($url . 'base');
        $this->assertEquals('GET', $client->get()->getMethod());
        $this->assertEquals('PUT', $client->put()->getMethod());
        $this->assertEquals('POST', $client->post()->getMethod());
        $this->assertEquals('HEAD', $client->head()->getMethod());
        $this->assertEquals('DELETE', $client->delete()->getMethod());
        $this->assertEquals('OPTIONS', $client->options()->getMethod());
        $this->assertEquals($url . 'base/abc', $client->get('abc')->getUrl());
        $this->assertEquals($url . 'zxy', $client->put('/zxy')->getUrl());
        $this->assertEquals($url . 'zxy?a=b', $client->post('/zxy?a=b')->getUrl());
        $this->assertEquals($url . 'base?a=b', $client->head('?a=b')->getUrl());
        $this->assertEquals($url . 'base?a=b', $client->delete('/base?a=b')->getUrl());
    }

    /**
     * @covers Guzzle\Service\Client::getBaseUrl
     * @expectedException RuntimeException
     */
    public function testClientEnsuresBaseUrlIsSetWhenRetrievingIt()
    {
        $client = new Client('');
        $client->getBaseUrl();
    }

    /**
     * @covers Guzzle\Service\Client::createRequest
     */
    public function testClientInjectsConfigsIntoUrls()
    {
        $client = new Client('http://www.test.com/api/v1', array(
            'test' => '123'
        ));
        $request = $client->get('relative/{{test}}');
        $this->assertEquals('http://www.test.com/api/v1/relative/123', $request->getUrl());
    }

    /**
     * @covers Guzzle\Service\Client
     */
    public function testAllowsEmptyBaseUrl()
    {
        $client = new Client();
        $request = $client->get('http://www.google.com/');
        $this->assertEquals('http://www.google.com/', $request->getUrl());
        $request->setResponse(new Response(200), true);
        $request->send();
    }

    /**
     * @covers Guzzle\Service\Client::batch
     */
    public function testManagesRequestPool()
    {
        $client = new Client('http://localhost/');
        $plugin = new MockPlugin();
        $plugin->addResponse(new Response(200));
        $plugin->addResponse(new Response(200));
        $client->getEventManager()->attach($plugin);

        $responses = $client->batch(array(
            $client->get('/'),
            $client->head('/users')
        ));

        $this->assertInternalType('array', $responses);
        $this->assertEquals(2, count($responses));
        $this->assertEquals(200, $responses[0]->getStatusCode());
        $this->assertEquals(200, $responses[1]->getStatusCode());
    }

    /**
     * @covers Guzzle\Service\Client::setPool
     * @covers Guzzle\Service\Client::getPool
     */
    public function testManagesInternalPoolObject()
    {
        $client = new Client();
        $pool = $client->getPool();
        $this->assertInstanceOf('Guzzle\\Http\\Pool\\PoolInterface', $pool);

        $client->setPool(new Pool());
        $this->assertInstanceOf('Guzzle\\Http\\Pool\\PoolInterface', $client->getPool());
        $this->assertNotSame($pool, $client->getPool());
    }
}