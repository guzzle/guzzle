<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Http\Plugin\AsyncPlugin;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Plugin\OauthPlugin;
use Guzzle\Http\Curl\CurlHandle;
use Guzzle\Http\Exception\CurlException;
use Guzzle\Common\Event;
use Guzzle\Http\Client;

class AsyncPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Plugin\AsyncPlugin::getSubscribedEvents
     */
    public function testSubscribesToEvents()
    {
        $events = AsyncPlugin::getSubscribedEvents();
        $this->assertArrayHasKey('request.before_send', $events);
        $this->assertArrayHasKey('request.exception', $events);
        $this->assertArrayHasKey('curl.callback.progress', $events);
    }

    /**
     * @covers Guzzle\Http\Plugin\AsyncPlugin::onBeforeSend
     */
    public function testEnablesProgressCallbacks()
    {
        $p = new AsyncPlugin();
        $request = RequestFactory::getInstance()->create('PUT', 'http://www.example.com');
        $event = new Event(array(
            'request' => $request
        ));
        $p->onBeforeSend($event);
        $this->assertEquals(true, $request->getCurlOptions()->get('progress'));
    }

    /**
     * @covers Guzzle\Http\Plugin\AsyncPlugin::onCurlProgess
     */
    public function testAddsTimesOutAfterSending()
    {
        $p = new AsyncPlugin();
        $request = RequestFactory::getInstance()->create('PUT', 'http://www.example.com');
        $handle = CurlHandle::factory($request);
        $event = new Event(array(
            'request'     => $request,
            'handle'      => $handle,
            'uploaded'    => 10,
            'upload_size' => 10,
            'downloaded'  => 0
        ));
        $p->onCurlProgess($event);
        $this->assertEquals(1, $handle->getOptions()->get(CURLOPT_TIMEOUT_MS));
        $this->assertEquals(true, $handle->getOptions()->get(CURLOPT_NOBODY));
    }

    /**
     * @covers Guzzle\Http\Plugin\AsyncPlugin::onCurlProgess
     */
    public function testEnsuresRequestIsSet()
    {
        $p = new AsyncPlugin();
        $event = new Event(array(
            'uploaded'    => 10,
            'upload_size' => 10,
            'downloaded'  => 0
        ));
        $p->onCurlProgess($event);
    }

    /**
     * @covers Guzzle\Http\Plugin\AsyncPlugin::onRequestTimeout
     */
    public function testMasksCurlExceptions()
    {
        $p = new AsyncPlugin();
        $request = RequestFactory::getInstance()->create('PUT', 'http://www.example.com');
        $e = new CurlException('Error');
        $event = new Event(array(
            'request'   => $request,
            'exception' => $e
        ));
        $p->onRequestTimeout($event);
        $this->assertEquals(RequestInterface::STATE_COMPLETE, $request->getState());
        $this->assertEquals(200, $request->getResponse()->getStatusCode());
        $this->assertTrue($request->getResponse()->hasHeader('X-Guzzle-Async'));
    }

    public function testEnsuresIntegration()
    {
        $this->getServer()->enqueue("HTTP/1.1 204 FOO\r\nContent-Length: 4\r\n\r\ntest");
        $client = new Client($this->getServer()->getUrl());
        $request = $client->post('/', null, array(
            'foo' => 'bar'
        ));
        $request->getEventDispatcher()->addSubscriber(new AsyncPlugin());
        $request->send();
        $this->assertEquals('', $request->getResponse()->getBody(true));
        $this->assertTrue($request->getResponse()->hasHeader('X-Guzzle-Async'));
    }
}
