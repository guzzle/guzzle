<?php

namespace GuzzleHttp\Tests\Subscriber;

use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Subscriber\Cookie;
use GuzzleHttp\CookieJar\SetCookie;
use GuzzleHttp\CookieJar\ArrayCookieJar;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Subscriber\History;
use GuzzleHttp\Subscriber\Mock;

/**
 * @covers GuzzleHttp\Subscriber\Cookie
 */
class CookieTest extends \PHPUnit_Framework_TestCase
{
    public function testExtractsAndStoresCookies()
    {
        $request = new Request('GET', '/');
        $response = new Response(200);
        $mock = $this->getMockBuilder('GuzzleHttp\CookieJar\ArrayCookieJar')
            ->setMethods(array('addCookiesFromResponse'))
            ->getMock();

        $mock->expects($this->exactly(1))
            ->method('addCookiesFromResponse')
            ->with($request, $response);

        $plugin = new Cookie($mock);
        $t = new Transaction(new Client(), $request);
        $t->setResponse($response);
        $plugin->onRequestSent(new CompleteEvent($t));
    }

    public function testProvidesCookieJar()
    {
        $jar = new ArrayCookieJar();
        $plugin = new Cookie($jar);
        $this->assertSame($jar, $plugin->getCookieJar());
    }

    public function testAddsCookiesToRequests()
    {
        $cookie = new SetCookie(['Name' => 'foo', 'Value' => 'bar;bam']);
        $mock = $this->getMockBuilder('GuzzleHttp\CookieJar\ArrayCookieJar')
            ->setMethods(array('getMatchingCookies'))
            ->getMock();
        $mock->expects($this->once())
            ->method('getMatchingCookies')
            ->will($this->returnValue([$cookie]));

        $plugin = new Cookie($mock);
        $client = new Client();
        $client->getEmitter()->addSubscriber($plugin);
        $request = $client->createRequest('GET', 'http://www.example.com');
        $t = new Transaction(new Client(), $request);
        $plugin->onRequestBeforeSend(new BeforeEvent($t));
        $this->assertEquals('foo="bar;bam"', $request->getHeader('Cookie'));
    }

    public function testCookiesAreExtractedFromRedirectResponses()
    {
        $jar = new ArrayCookieJar();
        $cookie = new Cookie($jar);
        $history = new History();
        $mock = new Mock([
            "HTTP/1.1 302 Moved Temporarily\r\n" .
            "Set-Cookie: test=583551; Domain=www.foo.com; Expires=Wednesday, 23-Mar-2050 19:49:45 GMT; Path=/\r\n" .
            "Location: /redirect\r\n\r\n",
            "HTTP/1.1 200 OK\r\n" .
            "Content-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\n" .
            "Content-Length: 0\r\n\r\n"
        ]);
        $client = new Client(['base_url' => 'http://www.foo.com']);
        $client->getEmitter()->addSubscriber($cookie);
        $client->getEmitter()->addSubscriber($mock);
        $client->getEmitter()->addSubscriber($history);

        $client->get();
        $request = $client->createRequest('GET', '/');
        $client->send($request);

        $this->assertEquals('test=583551', $request->getHeader('Cookie'));
        $requests = $history->getRequests();
        // Confirm subsequent requests have the cookie.
        $this->assertEquals('test=583551', $requests[2]->getHeader('Cookie'));
        // Confirm the redirected request has the cookie.
        $this->assertEquals('test=583551', $requests[1]->getHeader('Cookie'));
    }
}
