<?php

namespace Guzzle\Tests\Plugin\Backoff;

use Guzzle\Plugin\Backoff\TruncatedBackoffStrategy;

/**
 * @covers Guzzle\Plugin\Backoff\TruncatedBackoffStrategy
 */
class TruncatedBackoffStrategyTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testRetriesWhenLessThanMax()
    {
        $strategy = new TruncatedBackoffStrategy(2);
        $request = $this->getMock('Guzzle\Http\Message\Request', array(), array(), '', false);
        $this->assertEquals(0, $strategy->getBackoffPeriod(0, $request));
        $this->assertEquals(0, $strategy->getBackoffPeriod(1, $request));
        $this->assertFalse($strategy->getBackoffPeriod(2, $request));
    }
}
