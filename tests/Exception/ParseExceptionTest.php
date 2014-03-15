<?php

namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Exception\ParseException;
use GuzzleHttp\Message\Response;

/**
 * @covers GuzzleHttp\Exception\ParseException
 */
class ParseExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testHasResponse()
    {
        $res = new Response(200);
        $e = new ParseException('foo', $res);
        $this->assertSame($res, $e->getResponse());
        $this->assertEquals('foo', $e->getMessage());
    }
}
