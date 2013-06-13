<?php

namespace Guzzle\Tests\Plugin\Mock\Exception;

use Guzzle\Plugin\Mock\Exception\UnmatchedRequestException;
use Guzzle\Tests\GuzzleTestCase;

/**
 * @covers Guzzle\Plugin\Mock\Exception\UnmatchedRequestException
 */
class UnmatchedRequestExceptionTest extends GuzzleTestCase
{
    public function testInstanceOf()
    {
        $exception = new UnmatchedRequestException();

        $this->assertInstanceOf('OutOfBoundsException', $exception);
    }
}
