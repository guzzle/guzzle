<?php

namespace Guzzle\Tests\Stream;

use Guzzle\Stream\Stream;
use Guzzle\Stream\NoSeekStream;

/**
 * @covers Guzzle\Stream\NoSeekStream
 */
class NoSeekStreamTest extends \PHPUnit_Framework_TestCase
{
    public function testCannotSeek()
    {
        $s = $this->getMockBuilder('Guzzle\Stream\StreamInterface')
            ->setMethods(['isSeekable', 'seek'])
            ->getMockForAbstractClass();
        $s->expects($this->never())->method('seek');
        $s->expects($this->never())->method('isSeekable');
        $wrapped = new NoSeekStream($s);
        $this->assertFalse($wrapped->isSeekable());
        $this->assertFalse($wrapped->seek(2));
    }
}
