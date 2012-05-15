<?php

namespace Guzzle\Tests\Http;

use Guzzle\Common\Collection;
use Guzzle\Common\Log\ClosureLogAdapter;
use Guzzle\Http\Parser\UriTemplate\UriTemplate;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Plugin\ExponentialBackoffPlugin;
use Guzzle\Http\Plugin\LogPlugin;
use Guzzle\Http\Plugin\MockPlugin;
use Guzzle\Http\Curl\CurlMulti;
use Guzzle\Http\Client;
use Guzzle\Http\Utils;

/**
 * @group server
 */
class ClientTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
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
     * @covers Guzzle\Http\Client::getConfig
     * @covers Guzzle\Http\Client::setConfig
     * @covers Guzzle\Http\Client::setBaseUrl
     * @covers Guzzle\Http\Client::getBaseUrl
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
     * @covers Guzzle\Http\Client::getAllEvents
     */
    public function testDescribesEvents()
    {
        $this->assertEquals(array('client.create_request'), Client::getAllEvents());
    }

    /**
     * @covers Guzzle\Http\Client::__toString
     */
    public function testConvertsToString()
    {
        $client = new Client();
        $hash = spl_object_hash($client);
        $this->assertEquals($hash, (string) $client);
    }

    /**
     * @covers Guzzle\Http\Client::__construct
     */
    public function testConstructorCanAcceptConfig()
    {
        $client = new Client('http://www.test.com/', array(
            'data' => '123'
        ));
        $this->assertEquals('123', $client->getConfig('data'));
    }

    /**
     * @covers Guzzle\Http\Client::setConfig
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
     * @covers Guzzle\Http\Client
     */
    public function testExpandsUriTemplatesUsingConfig()
    {
        $client = new Client('http://www.google.com/');
        $client->setConfig(array(
            'api' => 'v1',
            'key' => 'value',
            'foo' => 'bar'
        ));
        $this->assertEquals('Testing...api/v1/key/value', $client->expandTemplate('Testing...api/{api}/key/{{key}}'));

        // Make sure that the client properly validates and injects config
        $this->assertEquals('bar', $client->getConfig('foo'));
    }

    /**
     * @covers Guzzle\Http\Client::__construct
     * @covers Guzzle\Http\Client::createRequest
     */
    public function testClientAttachersObserversToRequests()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");

        $client = new Client($this->getServer()->getUrl());
        $logPlugin = $this->getLogPlugin();
        $client->getEventDispatcher()->addSubscriber($logPlugin);

        // Get a request from the client and ensure the the observer was
        // attached to the new request
        $request = $client->createRequest();
        $this->assertTrue($this->hasSubscriber($request, $logPlugin));
    }

    /**
     * @covers Guzzle\Http\Client::getBaseUrl
     * @covers Guzzle\Http\Client::setBaseUrl
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
     * @covers Guzzle\Http\Client::setUserAgent
     * @covers Guzzle\Http\Client::createRequest
     * @covers Guzzle\Http\Client::prepareRequest
     */
    public function testSetsUserAgent()
    {
        $client = new Client('http://www.test.com/', array(
            'api' => 'v1'
        ));

        $this->assertSame($client, $client->setUserAgent('Test/1.0Ab', true));
        $this->assertEquals('Test/1.0Ab ' . Utils::getDefaultUserAgent(), $client->get()->getHeader('User-Agent'));
        $client->setUserAgent('Test/1.0Ab');
        $this->assertEquals('Test/1.0Ab', $client->get()->getHeader('User-Agent'));
    }

    /**
     * @covers Guzzle\Http\Client::createRequest
     * @covers Guzzle\Http\Client::prepareRequest
     */
    public function testClientAddsCurlOptionsToRequests()
    {
        $client = new Client('http://www.test.com/', array(
            'api' => 'v1',
            // Adds the option using the curl values
            'curl.CURLOPT_HTTPAUTH' => 'CURLAUTH_DIGEST',
            'curl.abc' => 'not added',
            'curl.blacklist' => 'abc'
        ));

        $request = $client->createRequest();
        $options = $request->getCurlOptions();
        $this->assertEquals(CURLAUTH_DIGEST, $options->get(CURLOPT_HTTPAUTH));
        $this->assertNull($options->get('curl.abc'));
        $this->assertNull($options->get('curl.blacklist'));
    }

    /**
     * @covers Guzzle\Http\Client::createRequest
     * @covers Guzzle\Http\Client::prepareRequest
     */
    public function testClientAddsCustomCurlOptionsToRequests()
    {
        $client = new Client('http://www.test.com/', array(
            'api' => 'v1',
            'curl.disable_wire' => true,
            'curl.foo' => 'bar'
        ));
        $request = $client->createRequest();
        $this->assertTrue($request->getCurlOptions()->get('disable_wire'));
        $this->assertEquals('bar', $request->getCurlOptions()->get('foo'));
    }

    /**
     * @covers Guzzle\Http\Client::prepareRequest
     */
    public function testClientAddsParamsToRequests()
    {
        $client = new Client('http://www.example.com', array(
            'api' => 'v1',
            'params.foo' => 'bar',
            'params.baz' => 'jar',
        ));
        $request = $client->createRequest();
        $this->assertEquals('bar', $request->getParams()->get('foo'));
        $this->assertEquals('jar', $request->getParams()->get('baz'));
    }

    public function urlProvider()
    {
        $u = $this->getServer()->getUrl() . 'base/';
        $u2 = $this->getServer()->getUrl() . 'base?z=1';
        return array(
            array($u, '', $u),
            array($u, 'relative/path/to/resource', $u . 'relative/path/to/resource'),
            array($u, 'relative/path/to/resource?a=b&c=d', $u . 'relative/path/to/resource?a=b&c=d'),
            array($u, '/absolute/path/to/resource', $this->getServer()->getUrl() . 'absolute/path/to/resource'),
            array($u, '/absolute/path/to/resource?a=b&c=d', $this->getServer()->getUrl() . 'absolute/path/to/resource?a=b&c=d'),
            array($u2, '/absolute/path/to/resource?a=b&c=d', $this->getServer()->getUrl()  . 'absolute/path/to/resource?a=b&c=d'),
            array($u2, 'relative/path/to/resource', $this->getServer()->getUrl() . 'base/relative/path/to/resource?z=1'),
            array($u2, 'relative/path/to/resource?another=query', $this->getServer()->getUrl() . 'base/relative/path/to/resource?z=1&another=query')
        );
    }

    /**
     * @dataProvider urlProvider
     * @covers Guzzle\Http\Client::createRequest
     */
    public function testBuildsRelativeUrls($baseUrl, $url, $result)
    {
        $client = new Client($baseUrl);
        $this->assertEquals($client->get($url)->getUrl(), $result);
    }

    /**
     * @covers Guzzle\Http\Client
     */
    public function testAllowsConfigsToBeChangedAndInjectedInBaseUrl()
    {
        $client = new Client('http://{{a}}/{{b}}');
        $this->assertEquals('http:///', $client->getBaseUrl());
        $this->assertEquals('http://{{a}}/{{b}}', $client->getBaseUrl(false));
        $client->setConfig(array(
            'a' => 'test.com',
            'b' => 'index.html'
        ));
        $this->assertEquals('http://test.com/index.html', $client->getBaseUrl());
    }

    /**
     * @covers Guzzle\Http\Client::createRequest
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
    }

    /**
     * @covers Guzzle\Http\Client::get
     * @covers Guzzle\Http\Client::delete
     * @covers Guzzle\Http\Client::head
     * @covers Guzzle\Http\Client::put
     * @covers Guzzle\Http\Client::post
     * @covers Guzzle\Http\Client::options
     * @covers Guzzle\Http\Client::patch
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
        $this->assertEquals('PATCH', $client->patch()->getMethod());
        $this->assertEquals($url . 'base/abc', $client->get('abc')->getUrl());
        $this->assertEquals($url . 'zxy', $client->put('/zxy')->getUrl());
        $this->assertEquals($url . 'zxy?a=b', $client->post('/zxy?a=b')->getUrl());
        $this->assertEquals($url . 'base?a=b', $client->head('?a=b')->getUrl());
        $this->assertEquals($url . 'base?a=b', $client->delete('/base?a=b')->getUrl());
    }

    /**
     * @covers Guzzle\Http\Client::createRequest
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
     * @covers Guzzle\Http\Client
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
     * @covers Guzzle\Http\Client::send
     * @covers Guzzle\Http\Client::setCurlMulti
     * @covers Guzzle\Http\Client::getCurlMulti
     */
    public function testAllowsCustomCurlMultiObjects()
    {
        $mock = $this->getMock('Guzzle\\Http\\Curl\\CurlMulti', array('add', 'send'));
        $mock->expects($this->once())
             ->method('add');
        $mock->expects($this->once())
             ->method('send');

        $client = new Client();
        $client->setCurlMulti($mock);

        $request = $client->get();
        $request->setResponse(new Response(200), true);
        $client->send($request);
    }

    /**
     * @covers Guzzle\Http\Client::send
     */
    public function testClientSendsMultipleRequests()
    {
        $client = new Client($this->getServer()->getUrl());
        $mock = new MockPlugin();

        $responses = array(
            new Response(200),
            new Response(201),
            new Response(202)
        );

        $mock->addResponse($responses[0]);
        $mock->addResponse($responses[1]);
        $mock->addResponse($responses[2]);

        $client->getEventDispatcher()->addSubscriber($mock);

        $requests = array(
            $client->get(),
            $client->head(),
            $client->put('/', null, 'test')
        );

        $this->assertEquals(array(
            $responses[0],
            $responses[1],
            $responses[2]
        ), $client->send($requests));
    }

    /**
     * @covers Guzzle\Http\Client::send
     */
    public function testClientSendsSingleRequest()
    {
        $client = new Client($this->getServer()->getUrl());
        $mock = new MockPlugin();
        $response = new Response(200);
        $mock->addResponse($response);
        $client->getEventDispatcher()->addSubscriber($mock);
        $this->assertEquals($response, $client->send($client->get()));
    }

    /**
     * @covers Guzzle\Http\Client::send
     * @expectedException Guzzle\Http\Exception\BadResponseException
     */
    public function testClientThrowsExceptionForSingleRequest()
    {
        $client = new Client($this->getServer()->getUrl());
        $mock = new MockPlugin();
        $response = new Response(404);
        $mock->addResponse($response);
        $client->getEventDispatcher()->addSubscriber($mock);
        $client->send($client->get());
    }

    /**
     * @covers Guzzle\Http\Client::send
     * @expectedException Guzzle\Common\Exception\ExceptionCollection
     */
    public function testClientThrowsExceptionForMultipleRequests()
    {
        $client = new Client($this->getServer()->getUrl());
        $mock = new MockPlugin();
        $mock->addResponse(new Response(200));
        $mock->addResponse(new Response(404));
        $client->getEventDispatcher()->addSubscriber($mock);
        $client->send(array($client->get(), $client->head()));
    }

    /**
     * @covers Guzzle\Http\Client
     */
    public function testQueryStringsAreNotDoubleEncoded()
    {
        $client = new Client('http://test.com', array(
            'path'  => array('foo', 'bar'),
            'query' => 'hi there',
            'data'  => array(
                'test' => 'a&b'
            )
        ));

        $request = $client->get('{/path*}{?query,data*}');
        $this->assertEquals('http://test.com/foo/bar?query=hi%20there&test=a%26b', $request->getUrl());
        $this->assertEquals('hi there', $request->getQuery()->get('query'));
        $this->assertEquals('a&b', $request->getQuery()->get('test'));
    }

    /**
     * @covers Guzzle\Http\Client
     */
    public function testQueryStringsAreNotDoubleEncodedUsingAbsolutePaths()
    {
        $client = new Client('http://test.com', array(
            'path'  => array('foo', 'bar'),
            'query' => 'hi there',
        ));
        $request = $client->get('http://test.com{?query}');
        $this->assertEquals('http://test.com/?query=hi%20there', $request->getUrl());
        $this->assertEquals('hi there', $request->getQuery()->get('query'));
    }

    /**
     * @covers Guzzle\Http\Client::setUriTemplate
     * @covers Guzzle\Http\Client::getUriTemplate
     */
    public function testAllowsUriTemplateInjection()
    {
        $client = new Client('http://test.com', array(
            'path'  => array('foo', 'bar'),
            'query' => 'hi there',
        ));

        $a = $client->getUriTemplate();
        $this->assertSame($a, $client->getUriTemplate());
        $client->setUriTemplate(new UriTemplate());
        $this->assertNotSame($a, $client->getUriTemplate());
    }

    /**
     * @covers Guzzle\Http\Client::expandTemplate
     */
    public function testAllowsCustomVariablesWhenExpandingTemplates()
    {
        $client = new Client('http://test.com', array(
            'test' => 'hi',
        ));

        $uri = $client->expandTemplate('http://{test}{?query*}', array(
            'query' => array(
                'han' => 'solo'
            )
        ));

        $this->assertEquals('http://hi?han=solo', $uri);
    }

    /**
     * @covers Guzzle\Http\Client::createRequest
     * @expectedException InvalidArgumentException
     */
    public function testUriArrayMustContainExactlyTwoElements()
    {
        $client = new Client();
        $client->createRequest('GET', array('haha!'));
    }

    /**
     * @covers Guzzle\Http\Client::createRequest
     * @expectedException InvalidArgumentException
     */
    public function testUriArrayMustContainAnArray()
    {
        $client = new Client();
        $client->createRequest('GET', array('haha!', 'test'));
    }

    /**
     * @covers Guzzle\Http\Client::createRequest
     * @covers Guzzle\Http\Client::get
     * @covers Guzzle\Http\Client::put
     * @covers Guzzle\Http\Client::post
     * @covers Guzzle\Http\Client::head
     * @covers Guzzle\Http\Client::options
     */
    public function testUriArrayAllowsCustomTemplateVariables()
    {
        $client = new Client();
        $vars = array(
            'var' => 'hi'
        );
        $this->assertEquals('/hi', (string) $client->createRequest('GET', array('/{var}', $vars))->getUrl());
        $this->assertEquals('/hi', (string) $client->get(array('/{var}', $vars))->getUrl());
        $this->assertEquals('/hi', (string) $client->put(array('/{var}', $vars))->getUrl());
        $this->assertEquals('/hi', (string) $client->post(array('/{var}', $vars))->getUrl());
        $this->assertEquals('/hi', (string) $client->head(array('/{var}', $vars))->getUrl());
        $this->assertEquals('/hi', (string) $client->options(array('/{var}', $vars))->getUrl());
    }

    /**
     * @covers Guzzle\Http\Client::setDefaultHeaders
     * @covers Guzzle\Http\Client::getDefaultHeaders
     * @covers Guzzle\Http\Client::createRequest
     */
    public function testAllowsDefaultHeaders()
    {
        $default = array(
            'X-Test' => 'Hi!'
        );
        $other = array(
            'X-Other' => 'Foo'
        );

        $client = new Client();
        $client->setDefaultHeaders($default);
        $this->assertEquals($default, $client->getDefaultHeaders()->getAll());
        $client->setDefaultHeaders(new Collection($default));
        $this->assertEquals($default, $client->getDefaultHeaders()->getAll());

        $request = $client->createRequest('GET', null, $other);
        $this->assertEquals('Hi!', $request->getHeader('X-Test'));
        $this->assertEquals('Foo', $request->getHeader('X-Other'));

        $request = $client->createRequest('GET', null, new Collection($other));
        $this->assertEquals('Hi!', $request->getHeader('X-Test'));
        $this->assertEquals('Foo', $request->getHeader('X-Other'));

        $request = $client->createRequest('GET');
        $this->assertEquals('Hi!', $request->getHeader('X-Test'));
    }

    /**
     * @covers Guzzle\Http\Client::setDefaultHeaders
     * @expectedException InvalidArgumentException
     */
    public function testValidatesDefaultHeaders()
    {
        $client = new Client();
        $client->setDefaultHeaders('foo');
    }
}
