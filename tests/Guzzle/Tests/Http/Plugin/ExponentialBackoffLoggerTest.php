<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Common\Log\ClosureLogAdapter;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Http\Plugin\ExponentialBackoffLogger;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;

/**
 * @covers Guzzle\Http\Plugin\ExponentialBackoffLogger
 */
class ExponentialBackoffLoggerTest extends \Guzzle\Tests\GuzzleTestCase
{
    public $message;

    public function setUp()
    {
        $this->message = '';
    }

    public function testHasEventList()
    {
        $this->assertEquals(1, count(ExponentialBackoffLogger::getSubscribedEvents()));
    }

    public function testLogsEvents()
    {
        list($logPlugin, $request, $response) = $this->getMocks();

        $response = $this->getMockBuilder('Guzzle\Http\Message\Response')
            ->setConstructorArgs(array(503))
            ->setMethods(array('getInfo'))
            ->getMock();

        $response->expects($this->any())
            ->method('getInfo')
            ->will($this->returnValue(2));

        $handle = $this->getMockHandle();

        $event = new Event(array(
            'request'  => $request,
            'response' => $response,
            'retries'  => 1,
            'delay'    => 3,
            'handle'   => $handle
        ));

        $logPlugin->onRequestRetry($event);
        $this->assertContains(
            '] PUT http://www.example.com/ - 503 Service Unavailable - Retries: 1, Delay: 3, Time: 2, 2, cURL: 30 Foo',
            $this->message
        );
    }

    public function testLogsEventsWithCustomTemplate()
    {
        list($logPlugin, $request, $response) = $this->getMocks();
        $logPlugin->setTemplate('Foo: {header_foo}, Method: {method}');
        $event = new Event(array(
            'request'  => $request,
            'response' => $response
        ));
        $logPlugin->onRequestRetry($event);
        $this->assertEquals("Foo: Bar, Method: PUT\n", $this->message);
    }

    public function testUsesCurlHandleForTimesWhenResponseNotAvailable()
    {
        list($logPlugin, $request, $response) = $this->getMocks();
        $logPlugin->setTemplate('{method}: {connect_time}, {total_time}');
        $event = new Event(array(
            'request' => $request,
            'handle'  => $this->getMockHandle()
        ));
        $logPlugin->onRequestRetry($event);
        $this->assertEquals("PUT: 2, 2\n", $this->message);
    }

    /**
     * @return array
     */
    protected function getMocks()
    {
        $that = $this;
        $logger = new ClosureLogAdapter(function ($message) use ($that) {
            $that->message .= $message . "\n";
        });
        $logPlugin = new ExponentialBackoffLogger($logger);
        $response = new Response(503);
        $request = RequestFactory::getInstance()->create('PUT', 'http://www.example.com', array(
            'Content-Length' => 3,
            'Foo'            => 'Bar'
        ));

        return array($logPlugin, $request, $response);
    }

    /**
     * @return CurlHandle
     */
    protected function getMockHandle()
    {
        $handle = $this->getMockBuilder('Guzzle\Http\Curl\CurlHandle')
            ->disableOriginalConstructor()
            ->setMethods(array('getError', 'getErrorNo', 'getInfo'))
            ->getMock();

        $handle->expects($this->once())
            ->method('getError')
            ->will($this->returnValue('Foo'));

        $handle->expects($this->once())
            ->method('getErrorNo')
            ->will($this->returnValue(30));

        $handle->expects($this->any())
            ->method('getInfo')
            ->will($this->returnValue(2));

        return $handle;
    }
}
