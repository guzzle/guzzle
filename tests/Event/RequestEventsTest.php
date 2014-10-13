<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Event\RequestEvents;

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

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testValidatesEventFormat()
    {
        RequestEvents::convertEventArray(['foo' => false], ['foo'], []);
    }
}
