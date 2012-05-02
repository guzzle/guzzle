<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Http\Exception\CurlException;
use Guzzle\Http\Client;
use Guzzle\Http\Plugin\ExponentialBackoffPlugin;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Curl\CurlMulti;
use Guzzle\Http\Curl\CurlMultiInterface;

/**
 * @group server
 */
class ExponentialBackoffPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function delayClosure($retries)
    {
        return 0;
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::__construct
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::getFailureCodes
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::getMaxRetries
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::setMaxRetries
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::setFailureCodes
     */
    public function testConstructsCorrectly()
    {
        $plugin = new ExponentialBackoffPlugin(2, array(500, 503, 502), array($this, 'delayClosure'));
        $this->assertEquals(2, $plugin->getMaxRetries());
        $this->assertEquals(array(500, 503, 502), $plugin->getFailureCodes());

        // You can specify any codes you want... Probably not a good idea though
        $plugin->setFailureCodes(array(200, 204));
        $this->assertEquals(array(200, 204), $plugin->getFailureCodes());
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::calculateWait
     */
    public function testCalculateWait()
    {
        $plugin = new ExponentialBackoffPlugin(2);
        $this->assertEquals(1, $plugin->calculateWait(0));
        $this->assertEquals(2, $plugin->calculateWait(1));
        $this->assertEquals(4, $plugin->calculateWait(2));
        $this->assertEquals(8, $plugin->calculateWait(3));
        $this->assertEquals(16, $plugin->calculateWait(4));
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin
     */
    public function testRetriesRequests()
    {
        // Create a script to return several 500 and 503 response codes
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata"
        ));

        $plugin = new ExponentialBackoffPlugin(2, null, array($this, 'delayClosure'));
        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);
        $request = $client->get();
        $request->send();

        // Make sure it eventually completed successfully
        $this->assertEquals(200, $request->getResponse()->getStatusCode());
        $this->assertEquals('OK', $request->getResponse()->getReasonPhrase());
        $this->assertEquals('data', $request->getResponse()->getBody(true));

        // Check that three requests were made to retry this request
        $this->assertEquals(3, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin
     */
    public function testRetriesRequestsUsingReasonPhraseMatch()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 400 FooError\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 400 FooError\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata"
        ));

        $plugin = new ExponentialBackoffPlugin(2, array('FooError'), array($this, 'delayClosure'));
        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);
        $request = $client->get();
        $request->send();

        // Make sure it eventually completed successfully
        $this->assertEquals(200, $request->getResponse()->getStatusCode());
        $this->assertEquals('data', $request->getResponse()->getBody(true));

        // Check that three requests were made to retry this request
        $this->assertEquals(3, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::onRequestSent
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::onRequestPoll
     * @covers Guzzle\Http\Message\Request
     * @expectedException Guzzle\Http\Exception\BadResponseException
     */
    public function testAllowsFailureOnMaxRetries()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n"
        ));

        $plugin = new ExponentialBackoffPlugin(2, null, array($this, 'delayClosure'));
        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);
        $request = $client->get();
        // This will fail because the plugin isn't retrying the request because
        // the max number of retries is exceeded (1 > 0)
        $request->send();
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::onRequestSent
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::onRequestPoll
     * @covers Guzzle\Http\Curl\CurlMulti
     */
    public function testRetriesPooledRequestsUsingDelayAndPollingEvent()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata"
        ));

        // Need to sleep for one second to make sure that the polling works
        // correctly in the observer
        $plugin = new ExponentialBackoffPlugin(1, null, function($r) {
            return 1;
        });

        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);
        $request = $client->get();
        $request->send();

        // Make sure it eventually completed successfully
        $this->assertEquals('data', $request->getResponse()->getBody(true));

        // Check that two requests were made to retry this request
        $this->assertEquals(2, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::getDefaultFailureCodes
     */
    public function testReturnsDefaultFailureCodes()
    {
        $this->assertNotEmpty(ExponentialBackoffPlugin::getDefaultFailureCodes());
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::__construct
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::getDefaultFailureCodes
     */
    public function testUsesDefaultFailureCodesByDefault()
    {
        $p = new ExponentialBackoffPlugin();
        $this->assertEquals($p->getFailureCodes(), ExponentialBackoffPlugin::getDefaultFailureCodes());
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::onRequestSent
     */
    public function testAllowsCallableFailureCodes()
    {
        $a = 0;
        $plugin = new ExponentialBackoffPlugin(1, function($request, $response) use (&$a) {
            // Look for a Foo header
            if ($response->hasHeader('Foo')) {
                $a = 1;
                return true;
            }
        }, array($this, 'delayClosure'));

        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nFoo: Bar\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata"
        ));

        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);
        $request = $client->get();
        $request->send();

        // Make sure it eventually completed successfully
        $this->assertEquals('data', $request->getResponse()->getBody(true));
        // Check that the callback saw the request and header
        $this->assertEquals(1, $a);
        // Check that two requests were made to retry this request
        $this->assertEquals(2, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::onRequestSent
     */
    public function testExponentiallyBacksOffCurlErrors()
    {
        $plugin = $this->getMock('Guzzle\Http\Plugin\ExponentialBackoffPlugin', array('retryRequest'));

        // Mock the retryRequest method so that it does nothing, but ensure
        // that it is called exactly once
        $plugin->expects($this->once())
            ->method('retryRequest')
            ->will($this->returnValue(null));

        // Create an exception that is found in the default curl exception list
        $exception = new CurlException('Curl');
        $exception->setError('foo', CURLE_OPERATION_TIMEOUTED);

        // Create a dummy event to send to the plugin
        $event = new Event(array(
            'request' => new Request('GET', 'http://test.com'),
            'response' => null,
            'exception' => $exception
        ));
        // Ensure the it uses the name we're looking for
        $event->setName('request.exception');

        // Trigger the event
        $plugin->onRequestSent($event);
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::onRequestSent
     */
    public function testAllowsCustomFailureMethodsToPuntToDefaultMethod()
    {
        $count = 0;

        $plugin = $this->getMockBuilder('Guzzle\Http\Plugin\ExponentialBackoffPlugin')
            ->setMethods(array('retryRequest'))
            ->setConstructorArgs(array(2, function() use (&$count) {
                $count++;
            }, array($this, 'delayClosure')))
            ->getMock();

        $plugin->expects($this->once())
            ->method('retryRequest')
            ->will($this->returnValue(null));

        $event = new Event(array(
            'request' => new Request('GET', 'http://test.com'),
            'response' => new Response(500)
        ));
        $event->setName('request.exception');

        $plugin->onRequestSent($event);
        $this->assertEquals(1, $count);
    }

    /**
     * @covers Guzzle\Http\Plugin\ExponentialBackoffPlugin::onRequestPoll
     */
    public function testSeeksToBeginningOfRequestBodyWhenRetrying()
    {
        // Create a mock curl multi object
        $multi = $this->getMockBuilder('Guzzle\Http\Curl\CurlMulti')
            ->setMethods(array('remove', 'add'))
            ->getMock();

        // Create a request with a body
        $request = new EntityEnclosingRequest('PUT', 'http://www.example.com');
        $request->setBody('abc');
        // Set the retry time to be something that will be retried always
        $request->getParams()->set('plugins.exponential_backoff.retry_time', 2);
        // Seek to the end of the stream
        $request->getBody()->seek(3);
        $this->assertEquals('', $request->getBody()->read(1));

        // Create a plugin that does not delay when retrying
        $plugin = new ExponentialBackoffPlugin(2, null, array($this, 'delayClosure'));

        // Create an event that is expected for the Poll event
        $event = new Event(array(
            'request'    => $request,
            'curl_multi' => $multi
        ));
        $event->setName(CurlMultiInterface::POLLING_REQUEST);

        $plugin->onRequestPoll($event);

        // Ensure that the stream was seeked to 0
        $this->assertEquals('a', $request->getBody()->read(1));
    }
}
