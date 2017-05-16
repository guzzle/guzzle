<?php

namespace Guzzle\Tests\Plugin\Mock\UnmatchedRequestStrategy;

use Guzzle\Plugin\Mock\UnmatchedRequestStrategy\NullUnmatchedRequestStrategy;
use Guzzle\Tests\GuzzleTestCase;

/**
 * @covers Guzzle\Plugin\Mock\UnmatchedRequestStrategy\NullUnmatchedRequestStrategy
 */
class NullUnmatchedRequestStrategyTest extends GuzzleTestCase
{
    public function testInstanceOf()
    {
        $strategy = new NullUnmatchedRequestStrategy();

        $this->assertInstanceOf(
            'Guzzle\Plugin\Mock\UnmatchedRequestStrategy\UnmatchedRequestStrategyInterface',
            $strategy
        );
    }

    public function testHandle()
    {
        $strategy = new NullUnmatchedRequestStrategy();

        $request = $this->getMock('Guzzle\Http\Message\RequestInterface');
        $request->expects($this->never())->method($this->anything());

        $strategy->handle($request);
    }
}
