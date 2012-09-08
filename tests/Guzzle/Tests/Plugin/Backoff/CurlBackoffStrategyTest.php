<?php

namespace Guzzle\Tests\Plugin\Backoff;

use Guzzle\Plugin\Backoff\CurlBackoffStrategy;
use Guzzle\Http\Exception\CurlException;

/**
 * @covers Guzzle\Plugin\Backoff\CurlBackoffStrategy
 * @covers Guzzle\Plugin\Backoff\AbstractErrorCodeBackoffStrategy
 */
class CurlBackoffStrategyTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testRetriesWithExponentialDelay()
    {
        $this->assertNotEmpty(CurlBackoffStrategy::getDefaultFailureCodes());
        $strategy = new CurlBackoffStrategy();
        $request = $this->getMock('Guzzle\Http\Message\Request', array(), array(), '', false);
        $e = new CurlException();
        $e->setError('foo', CURLE_BAD_CALLING_ORDER);
        $this->assertEquals(false, $strategy->getBackoffPeriod(0, $request, null, $e));

        foreach (CurlBackoffStrategy::getDefaultFailureCodes() as $code) {
            $this->assertEquals(0, $strategy->getBackoffPeriod(0, $request, null, $e->setError('foo', $code)));
        }
    }
}
