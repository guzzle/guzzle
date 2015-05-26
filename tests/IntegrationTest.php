<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Event\AbstractTransferEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\EndEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Pool;

class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @issue https://github.com/guzzle/guzzle/issues/867
     */
    public function testDoesNotFailInEventSystemForNetworkError()
    {
        $c = new Client();
        $r = $c->createRequest(
            'GET',
            Server::$url,
            [
                'timeout'         => 1,
                'connect_timeout' => 1,
                'proxy'           => 'http://127.0.0.1:123/foo'
            ]
        );

        $events = [];
        $fn = function(AbstractTransferEvent $event) use (&$events) {
            $events[] = [
                get_class($event),
                $event->hasResponse(),
                $event->getResponse()
            ];
        };

        $pool = new Pool($c, [$r], [
            'error'    => $fn,
            'end'      => $fn
        ]);

        $pool->wait();

        $this->assertCount(2, $events);
        $this->assertEquals('GuzzleHttp\Event\ErrorEvent', $events[0][0]);
        $this->assertFalse($events[0][1]);
        $this->assertNull($events[0][2]);

        $this->assertEquals('GuzzleHttp\Event\EndEvent', $events[1][0]);
        $this->assertFalse($events[1][1]);
        $this->assertNull($events[1][2]);
    }

    /**
     * @issue https://github.com/guzzle/guzzle/issues/866
     */
    public function testProperyGetsTransferStats()
    {
        $transfer = [];
        Server::enqueue([new Response(200)]);
        $c = new Client();
        $response = $c->get(Server::$url . '/foo', [
            'events' => [
                'end' => function (EndEvent $e) use (&$transfer) {
                    $transfer = $e->getTransferInfo();
                }
            ]
        ]);
        $this->assertEquals(Server::$url . '/foo', $response->getEffectiveUrl());
        $this->assertNotEmpty($transfer);
        $this->assertArrayHasKey('url', $transfer);
    }

    public function testNestedFutureResponsesAreResolvedWhenSending()
    {
        $c = new Client();
        $total = 3;
        Server::enqueue([
            new Response(200),
            new Response(201),
            new Response(202)
        ]);
        $c->getEmitter()->on(
            'complete',
            function (CompleteEvent $e) use (&$total) {
                if (--$total) {
                    $e->retry();
                }
            }
        );
        $response = $c->get(Server::$url);
        $this->assertEquals(202, $response->getStatusCode());
        $this->assertEquals('GuzzleHttp\Message\Response', get_class($response));
    }

    public function testNestedFutureErrorsAreResolvedWhenSending()
    {
        $c = new Client();
        $total = 3;
        Server::enqueue([
            new Response(500),
            new Response(501),
            new Response(502)
        ]);
        $c->getEmitter()->on(
            'error',
            function (ErrorEvent $e) use (&$total) {
                if (--$total) {
                    $e->retry();
                }
            }
        );
        try {
            $c->get(Server::$url);
            $this->fail('Did not throw!');
        } catch (RequestException $e) {
            $this->assertEquals(502, $e->getResponse()->getStatusCode());
        }
    }
    
    public function testDigestAuthWithBadPasswordDetectsRejection()
    {
        Server::enqueue([new Response(200)]);
        $c = new Client();
        try {
            $response = $c->get(Server::$url . 'secure/by-digest/anything');
        } catch(\GuzzleHttp\Exception\ClientException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
            }
        } catch(\GuzzleHttp\Exception\ServerException $e) {
            // 501 Exceptions are thrown when our test server is incomplete. Let us tell the user.
            if ($e->getCode() == 501) {
                $this->markTestSkipped('Cannot currently test HTTP Auth; please run an \'npm install http-auth\' to cover this case');
                return;
            }
            throw $e;
        }
        $this->assertEquals(401, $response->getStatusCode());
    }
    
    public function testDigestAuthWithRightPasswordPasses()
    {
        $c = new Client();
        // Digest has a way to prevent replay attacks by requiring a nonce to be incremented at each request. Ensure we pass multiple successive requests.
        for ($i = 3; --$i >= 0;) {
            Server::enqueue([new Response(200)]);
            try {
                $response = $c->get(Server::$url . 'secure/by-digest/anything', ['auth' => ['me', 'test', 'digest']]); // @todo Someday there should be autodetection of the authentification type, based on the return headers to the first rejected query.
            } catch(\GuzzleHttp\Exception\ServerException $e) {
                // 501 Exceptions are thrown when our test server is incomplete. Let us tell the user.
                if ($e->getCode() == 501) {
                    $this->markTestSkipped('Cannot currently test HTTP Auth; please run an \'npm install http-auth\' to cover this case');
                    break;
                }
                throw $e;
            }
            $this->assertEquals(200, $response->getStatusCode());
        }
    }
}
