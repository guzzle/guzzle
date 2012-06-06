<?php

namespace Guzzle\Tests\Common\Exception;

use Guzzle\Common\Exception\BatchTransferException;

class BatchTransferExceptionTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testContainsBatch()
    {
        $a = new \Exception('Baz!');
        $b = new BatchTransferException(array('foo'), $a);
        $this->assertEquals(array('foo'), $b->getBatch());
        $this->assertSame($a, $b->getPrevious());
    }
}
