<?php
namespace GuzzleHttp\Tests\Subscriber;

use GuzzleHttp\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Cookie;
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
        $mock = $this->getMockBuilder('GuzzleHttp\Cookie\CookieJar')
            ->setMethods(array('extractCookies'))
            ->getMock();

        $mock->expects($this->exactly(1))
            ->method('extractCookies')
            ->with($request, $response);

        $plugin = new Cookie($mock);
        $t = new Transaction(new Client(), $request);
        $t->response = $response;
        $plugin->onComplete(new CompleteEvent($t));
    }

    public function testProvidesCookieJar()
    {
        $jar = new CookieJar();
        $plugin = new Cookie($jar);
        $this->assertSame($jar, $plugin->getCookieJar());
    }

    public function testCookiesAreExtractedFromRedirectResponses()
    {
        $jar = new CookieJar();
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
        $client->getEmitter()->attach($cookie);
        $client->getEmitter()->attach($mock);
        $client->getEmitter()->attach($history);

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
