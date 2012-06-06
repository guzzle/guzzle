<?php

namespace Guzzle\Tests\Common;

use Guzzle\Common\Batch\BatchBuilder;

/**
 * @covers Guzzle\Common\Batch\BatchBuilder
 */
class BatchBuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    private function getMockTransfer()
    {
        return $this->getMock('Guzzle\Common\Batch\BatchTransferInterface');
    }

    private function getMockDivisor()
    {
        return $this->getMock('Guzzle\Common\Batch\BatchDivisorInterface');
    }

    private function getMockBatchBuilder()
    {
        return BatchBuilder::factory()
            ->transferWith($this->getMockTransfer())
            ->createBatchesWith($this->getMockDivisor());
    }

    public function testFactoryCreatesInstance()
    {
        $builder = BatchBuilder::factory();
        $this->assertInstanceOf('Guzzle\Common\Batch\BatchBuilder', $builder);
    }

    public function testAddsAutoFlush()
    {
        $batch = $this->getMockBatchBuilder()->autoFlushAt(10)->build();
        $this->assertInstanceOf('Guzzle\Common\Batch\FlushingBatch', $batch);
    }

    public function testAddsExceptionBuffering()
    {
        $batch = $this->getMockBatchBuilder()->bufferExceptions()->build();
        $this->assertInstanceOf('Guzzle\Common\Batch\ExceptionBufferingBatch', $batch);
    }

    public function testAddHistory()
    {
        $batch = $this->getMockBatchBuilder()->keepHistory()->build();
        $this->assertInstanceOf('Guzzle\Common\Batch\HistoryBatch', $batch);
    }

    public function testAddsNotify()
    {
        $batch = $this->getMockBatchBuilder()->notify(function() {})->build();
        $this->assertInstanceOf('Guzzle\Common\Batch\NotifyingBatch', $batch);
    }

    /**
     * @expectedException Guzzle\Common\Exception\RuntimeException
     */
    public function testTransferStrategyMustBeSet()
    {
        $batch = BatchBuilder::factory()->createBatchesWith($this->getMockDivisor())->build();
    }

    /**
     * @expectedException Guzzle\Common\Exception\RuntimeException
     */
    public function testDivisorStrategyMustBeSet()
    {
        $batch = BatchBuilder::factory()->transferWith($this->getMockTransfer())->build();
    }

    public function testTransfersRequests()
    {
        $batch = BatchBuilder::factory()->transferRequests(10)->build();
        $this->assertInstanceOf('Guzzle\Http\BatchRequestTransfer', $this->readAttribute($batch, 'transferStrategy'));
    }

    public function testTransfersCommands()
    {
        $batch = BatchBuilder::factory()->transferCommands(10)->build();
        $this->assertInstanceOf('Guzzle\Service\Command\BatchCommandTransfer', $this->readAttribute($batch, 'transferStrategy'));
    }
}
