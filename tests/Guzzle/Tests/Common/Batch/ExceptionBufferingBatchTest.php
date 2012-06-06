<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Batch\ExceptionBufferingBatch;
use Guzzle\Common\Batch\Batch;

/**
 * @covers Guzzle\Common\Batch\ExceptionBufferingBatch
 */
class ExceptionBufferingBatchTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testFlushesEntireBatchWhileBufferingErroredBatches()
    {
        $t = $this->getMock('Guzzle\Common\Batch\BatchTransferInterface', array('transfer'));
        $d = $this->getMock('Guzzle\Common\Batch\BatchDivisorInterface', array('createBatches'));
        $batch = new Batch($t, $d);

        $called = 0;
        $t->expects($this->exactly(3))
            ->method('transfer')
            ->will($this->returnCallback(function ($batch) use (&$called) {
                if (++$called === 1) {
                    throw new \Exception('Foo');
                }
            }));

        $queue = $this->readAttribute($batch, 'queue');
        $d->expects($this->any())
            ->method('createBatches')
            ->will($this->returnCallback(function ($d) use ($queue) {
                $items = array();
                foreach ($queue as $item) {
                    $items[] = $item;
                }
                return array_chunk($items, 1);
            }));

        $decorator = new ExceptionBufferingBatch($batch);
        $decorator->add('foo')->add('baz')->add('bar');
        $decorator->flush();

        $this->assertEquals(1, count($decorator->getExceptions()));
        $decorator->clearExceptions();
        $this->assertEquals(0, count($decorator->getExceptions()));
    }

    public function testBuffersAddItemExceptions()
    {
        $batch = $this->getMockBuilder('Guzzle\Common\Batch\Batch')
            ->setMethods(array('add'))
            ->disableOriginalConstructor()
            ->getMock();

        $batch->expects($this->once())
            ->method('add')
            ->will($this->throwException(new \Exception('foo')));

        $decorator = new ExceptionBufferingBatch($batch);
        $decorator->add('foo');
        $this->assertEquals(1, count($decorator->getExceptions()));
    }
}
