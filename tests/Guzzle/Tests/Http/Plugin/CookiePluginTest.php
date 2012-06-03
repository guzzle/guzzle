<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Common\Event;
use Guzzle\Http\Cookie;
use Guzzle\Http\Plugin\CookiePlugin;
use Guzzle\Http\CookieJar\ArrayCookieJar;
use Guzzle\Http\Client;
use Guzzle\Http\Message\Response;

/**
 * @group server
 * @covers Guzzle\Http\Plugin\CookiePlugin
 */
class CookiePluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testExtractsAndStoresCookies()
    {
        $response = new Response(200);
        $mock = $this->getMockBuilder('Guzzle\\Http\\CookieJar\\ArrayCookieJar')
            ->setMethods(array('addCookiesFromResponse'))
            ->getMock();

        $mock->expects($this->exactly(1))
            ->method('addCookiesFromResponse')
            ->with($response);

        $plugin = new CookiePlugin($mock);
        $plugin->onRequestSent(new Event(array(
            'response' => $response
        )));
    }

    public function testAddsCookiesToRequests()
    {
        $cookie = new Cookie(array(
            'name'  => 'foo',
            'value' => 'bar'
        ));

        $mock = $this->getMockBuilder('Guzzle\\Http\\CookieJar\\ArrayCookieJar')
            ->setMethods(array('getMatchingCookies'))
            ->getMock();

        $mock->expects($this->once())
            ->method('getMatchingCookies')
            ->will($this->returnValue(array($cookie)));

        $plugin = new CookiePlugin($mock);

        $client = new Client();
        $client->getEventDispatcher()->addSubscriber($plugin);

        $request = $client->get('http://www.example.com');
        $plugin->onRequestBeforeSend(new Event(array(
            'request' => $request
        )));

        $this->assertEquals('bar', $request->getCookie('foo'));
    }

    public function testCookiesAreExtractedFromRedirectResponses()
    {
        $plugin = new CookiePlugin(new ArrayCookieJar());
        $this->getServer()->enqueue(array(
            "HTTP/1.1 302 Moved Temporarily\r\n" .
            "Set-Cookie: test=583551; expires=Wednesday, 23-Mar-2050 19:49:45 GMT; path=/\r\n" .
            "Location: /redirect\r\n\r\n",

            "HTTP/1.1 200 OK\r\n" .
            "Content-Length: 0\r\n\r\n",

            "HTTP/1.1 200 OK\r\n" .
            "Content-Length: 0\r\n\r\n"
        ));

        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);

        $request = $client->get();
        $request->send();

        $request = $client->get();
        $request->send();

        $this->assertEquals('test=583551', $request->getHeader('Cookie'));
    }

    public function testCookiesAreNotAddedWhenParamIsSet()
    {
        $jar = new ArrayCookieJar();
        $plugin = new CookiePlugin($jar);

        $jar->add(new Cookie(array(
            'domain'  => 'example.com',
            'path'    => '/',
            'name'    => 'test',
            'value'   => 'hi',
            'expires' => time() + 3600
        )));

        $client = new Client('http://example.com');
        $client->getEventDispatcher()->addSubscriber($plugin);

        // Ensure that it is normally added
        $request = $client->get();
        $request->setResponse(new Response(200), true);
        $request->send();
        $this->assertEquals('hi', $request->getCookie('test'));

        // Now ensure that it is not added
        $request = $client->get();
        $request->getParams()->set('cookies.disable', true);
        $request->setResponse(new Response(200), true);
        $request->send();
        $this->assertNull($request->getCookie('test'));
    }

    public function testProvidesCookieJar()
    {
        $jar = new ArrayCookieJar();
        $plugin = new CookiePlugin($jar);
        $this->assertSame($jar, $plugin->getCookieJar());
    }
}
