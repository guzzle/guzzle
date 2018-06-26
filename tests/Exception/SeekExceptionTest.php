<?php
namespace GuzzleHttp\Tests\Exception;

use GuzzleHttp\Exception\SeekException;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;

class SeekExceptionTest extends TestCase
{
    public function testHasStream()
    {
        $s = Psr7\stream_for('foo');
        $e = new SeekException($s, 10);
        $this->assertSame($s, $e->getStream());
        $this->assertContains('10', $e->getMessage());
    }
}
