<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\TransferStats;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;

class TransferStatsTest extends TestCase
{
    public function testHasData()
    {
        $request = new Psr7\Request('GET', 'http://foo.com');
        $response = new Psr7\Response();
        $stats = new TransferStats(
            $request,
            $response,
            10.5,
            null,
            ['foo' => 'bar']
        );
        $this->assertSame($request, $stats->getRequest());
        $this->assertSame($response, $stats->getResponse());
        $this->assertTrue($stats->hasResponse());
        $this->assertEquals(['foo' => 'bar'], $stats->getHandlerStats());
        $this->assertEquals('bar', $stats->getHandlerStat('foo'));
        $this->assertSame($request->getUri(), $stats->getEffectiveUri());
        $this->assertEquals(10.5, $stats->getTransferTime());
        $this->assertNull($stats->getHandlerErrorData());
    }
}
