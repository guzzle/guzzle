<?php
namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\SeekException;
use GuzzleHttp\Psr7\Stream;

class SeekExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testHasStream()
    {
        $s = Stream::factory('foo');
        $e = new SeekException($s, 10);
        $this->assertSame($s, $e->getStream());
        $this->assertContains('10', $e->getMessage());
    }
}
