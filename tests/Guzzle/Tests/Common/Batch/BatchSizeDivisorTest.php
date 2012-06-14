<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Batch\BatchSizeDivisor;

/**
 * @covers Guzzle\Common\Batch\BatchSizeDivisor
 */
class BatchSizeDivisorTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testDividesBatch()
    {
        $queue = new \SplQueue();
        $queue[] = 'foo';
        $queue[] = 'baz';
        $queue[] = 'bar';
        $d = new BatchSizeDivisor(3);
        $this->assertEquals(3, $d->getSize());
        $d->setSize(2);
        $batches = $d->createBatches($queue);
        $this->assertEquals(array(array('foo', 'baz'), array('bar')), $batches);
    }
}
