<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Batch\NotifyingBatch;
use Guzzle\Common\Batch\Batch;

/**
 * @covers Guzzle\Common\Batch\NotifyingBatch
 */
class NotifyingBatchTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testNotifiesAfterFlush()
    {
        $batch = $this->getMock('Guzzle\Common\Batch\Batch', array('flush'), array(
            $this->getMock('Guzzle\Common\Batch\BatchTransferInterface'),
            $this->getMock('Guzzle\Common\Batch\BatchDivisorInterface')
        ));

        $batch->expects($this->once())
            ->method('flush')
            ->will($this->returnValue(array('foo', 'baz')));

        $data = array();
        $decorator = new NotifyingBatch($batch, function ($batch) use (&$data) {
            $data[] = $batch;
        });

        $decorator->add('foo')->add('baz');
        $decorator->flush();
        $this->assertEquals(array(array('foo', 'baz')), $data);
    }

    /**
     * @expectedException Guzzle\Common\Exception\InvalidArgumentException
     */
    public function testEnsuresCallableIsValid()
    {
        $batch = new Batch(
            $this->getMock('Guzzle\Common\Batch\BatchTransferInterface'),
            $this->getMock('Guzzle\Common\Batch\BatchDivisorInterface')
        );
        $decorator = new NotifyingBatch($batch, 'foo');
    }
}
