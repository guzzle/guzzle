<?php

namespace Guzzle\Tests\Http;

use Guzzle\Http\EntityBody;
use Guzzle\Http\ReadLimitEntityBody;

/**
 * @covers Guzzle\Http\ReadLimitEntityBody
 */
class ReadLimitEntityBodyTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var ReadLimitEntityBody
     */
    protected $body;

    public function setUp()
    {
        $this->body = new ReadLimitEntityBody(EntityBody::factory(fopen(__FILE__, 'r')), 10, 3);
    }

    public function testSeeksWhenConstructed()
    {
        $this->assertEquals(3, $this->body->ftell());
    }

    /**
     * @expectedException Guzzle\Common\Exception\RuntimeException
     */
    public function testThrowsExceptionWhenAttemptingToSeek()
    {
        $this->assertFalse($this->body->isSeekable());
        $this->body->seek(10);
    }

    public function testReadsOnlySubsetOfData()
    {
        $data = $this->body->read(100);
        $this->assertEquals(10, strlen($data));
        $this->assertFalse($this->body->read(1000));

        $this->body->setOffset(10);
        $newData = $this->body->read(100);
        $this->assertEquals(10, strlen($newData));
        $this->assertNotSame($data, $newData);
    }

    public function testClaimsConsumedWhenReadLimitIsReached()
    {
        $this->assertFalse($this->body->isConsumed());
        $this->body->read(1000);
        $this->assertTrue($this->body->isConsumed());
    }
}
