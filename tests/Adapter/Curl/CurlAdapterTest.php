<?php

namespace GuzzleHttp\Tests\Adapter\Curl;

require_once __DIR__ . '/AbstractCurl.php';

use GuzzleHttp\Adapter\Curl\CurlAdapter;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\HeadersEvent;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Tests\Server;

/**
 * @covers GuzzleHttp\Adapter\Curl\CurlAdapter
 */
class CurlAdapterTest extends AbstractCurl
{
    protected function setUp()
    {
        if (!function_exists('curl_reset')) {
            $this->markTestSkipped('curl_reset() is not available');
        }
    }

    protected function getAdapter($factory = null, $options = [])
    {
        return new CurlAdapter($factory ?: new MessageFactory(), $options);
    }

    public function testCanSetMaxHandles()
    {
        $a = new CurlAdapter(new MessageFactory(), ['max_handles' => 10]);
        $this->assertEquals(10, $this->readAttribute($a, 'maxHandles'));
    }

    public function testCanInterceptBeforeSending()
    {
        $client = new Client();
        $request = new Request('GET', 'http://httpbin.org/get');
        $response = new Response(200);
        $request->getEmitter()->on(
            'before',
            function (BeforeEvent $e) use ($response) {
                $e->intercept($response);
            }
        );
        $transaction = new Transaction($client, $request);
        $f = 'does_not_work';
        $a = new CurlAdapter(new MessageFactory(), ['handle_factory' => $f]);
        $a->send($transaction);
        $this->assertSame($response, $transaction->getResponse());
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage cURL error
     */
    public function testThrowsCurlErrors()
    {
        $client = new Client();
        $request = $client->createRequest('GET', 'http://localhost:123', [
            'connect_timeout' => 0.001,
            'timeout' => 0.001,
        ]);
        $transaction = new Transaction($client, $request);
        $a = new CurlAdapter(new MessageFactory());
        $a->send($transaction);
    }

    public function testHandlesCurlErrors()
    {
        $client = new Client();
        $request = $client->createRequest('GET', 'http://localhost:123', [
            'connect_timeout' => 0.001,
            'timeout' => 0.001,
        ]);
        $r = new Response(200);
        $request->getEmitter()->on('error', function (ErrorEvent $e) use ($r) {
            $e->intercept($r);
        });
        $transaction = new Transaction($client, $request);
        $a = new CurlAdapter(new MessageFactory());
        $a->send($transaction);
        $this->assertSame($r, $transaction->getResponse());
    }

    public function testReleasesAdditionalEasyHandles()
    {
        Server::flush();
        Server::enqueue([
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
        ]);
        $a = new CurlAdapter(new MessageFactory(), ['max_handles' => 2]);
        $client = new Client(['base_url' => Server::$url, 'adapter' => $a]);
        $request = $client->createRequest('GET', '/', [
            'events' => [
                'headers' => function (HeadersEvent $e) use ($client) {
                    $client->get('/', [
                        'events' => [
                            'headers' => function (HeadersEvent $e) {
                                $e->getClient()->get('/');
                            }
                        ]
                    ]);
                }
            ]
        ]);
        $transaction = new Transaction($client, $request);
        $a->send($transaction);
        $this->assertCount(2, $this->readAttribute($a, 'handles'));
    }
}
