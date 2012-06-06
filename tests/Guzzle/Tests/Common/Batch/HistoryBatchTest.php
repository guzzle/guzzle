<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Batch\HistoryBatch;
use Guzzle\Common\Batch\Batch;

/**
 * @covers Guzzle\Common\Batch\HistoryBatch
 */
class HistoryBatchTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testMaintainsHistoryOfItemsAddedToBatch()
    {
        $batch = new Batch(
            $this->getMock('Guzzle\Common\Batch\BatchTransferInterface'),
            $this->getMock('Guzzle\Common\Batch\BatchDivisorInterface')
        );

        $history = new HistoryBatch($batch);
        $history->add('foo')->add('baz');
        $this->assertEquals(array('foo', 'baz'), $history->getHistory());
        $history->clearHistory();
        $this->assertEquals(array(), $history->getHistory());
    }
}
