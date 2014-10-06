<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Client;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Ring\Client\MockAdapter;
use GuzzleHttp\Event\EndEvent;
use GuzzleHttp\Ring\Future\FutureArray;
use React\Promise\Deferred;

/**
 * @covers GuzzleHttp\Event\RequestEvents
 */
class RequestEventsTest extends \PHPUnit_Framework_TestCase
{
    public function prepareEventProvider()
    {
        $cb = function () {};

        return [
            [[], ['complete'], $cb, ['complete' => [$cb]]],
            [
                ['complete' => $cb],
                ['complete'],
                $cb,
                ['complete' => [$cb, $cb]]
            ],
            [
                ['prepare' => []],
                ['error', 'foo'],
                $cb,
                [
                    'prepare' => [],
                    'error'   => [$cb],
                    'foo'     => [$cb]
                ]
            ],
            [
                ['prepare' => []],
                ['prepare'],
                $cb,
                [
                    'prepare' => [$cb]
                ]
            ],
            [
                ['prepare' => ['fn' => $cb]],
                ['prepare'], $cb,
                [
                    'prepare' => [
                        ['fn' => $cb],
                        $cb
                    ]
                ]
            ],
        ];
    }

    /**
     * @dataProvider prepareEventProvider
     */
    public function testConvertsEventArrays(
        array $in,
        array $events,
        $add,
        array $out
    ) {
        $result = RequestEvents::convertEventArray($in, $events, $add);
        $this->assertEquals($out, $result);
    }

    public function adapterResultProvider()
    {
        $deferred = new Deferred();
        $future = new FutureArray(
            $deferred->promise(),
            function () use ($deferred) {
                $deferred->resolve(['status' => 404]);
            }
        );

        return [
            [['status' => 404]],
            [$future]
        ];
    }

    /**
     * @dataProvider adapterResultProvider
     */
    public function testCanInterceptExceptionsInDoneEvent($res)
    {
        $adapter = new MockAdapter($res);
        $client = new Client(['adapter' => $adapter]);
        $request = $client->createRequest('GET', 'http://www.foo.com');
        $request->getEmitter()->on('end', function (EndEvent $e) {
            RequestEvents::cancelEndEvent($e);
        });
        $response = $client->send($request);
        try {
            $response->getStatusCode();
            $this->fail('Did not throw');
        } catch (\Exception $e) {
            $this->assertContains('Cancelled future', $e->getMessage());
            $this->assertContains('404', $e->getPrevious()->getMessage());
        }
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesEventFormat()
    {
        RequestEvents::convertEventArray(['foo' => false], ['foo'], []);
    }
}
