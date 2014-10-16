<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Event\AbstractTransferEvent;
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
}
