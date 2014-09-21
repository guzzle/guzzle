<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Client;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Ring\Client\MockAdapter;
use GuzzleHttp\Event\EndEvent;
use GuzzleHttp\Ring\Future;

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
        return [
            [['status' => 404]],
            [new Future(function () { return ['status' => 404]; })]
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
            RequestEvents::stopException($e);
        });
        $response = $client->send($request);
        $this->assertInstanceOf('GuzzleHttp\Message\FutureResponse', $response);
        try {
            $response->getStatusCode();
            $this->fail('Did not throw');
        } catch (\Exception $e) {
            $this->assertContains('404', $e->getMessage());
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
