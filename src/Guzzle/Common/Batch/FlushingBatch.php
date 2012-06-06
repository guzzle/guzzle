<?php

namespace Guzzle\Common\Batch;

/**
 * BatchInterface decorator used to add automatic flushing of the queue when the
 * size of the queue reaches a threshold
 */
class FlushingBatch extends AbstractBatchDecorator
{
    /**
     * @var int The threshold for which to automatically flush
     */
    protected $threshold;

    /**
     * @param BatchInterface $decoratedBatch  BatchInterface that is being decorated
     * @param int            $threshold       Flush when the number in queue matches the threshold
     */
    public function __construct(BatchInterface $decoratedBatch, $threshold)
    {
        $this->threshold = $threshold;
        parent::__construct($decoratedBatch);
    }

    /**
     * {@inheritdoc}
     */
    public function add($item)
    {
        $this->decoratedBatch->add($item);
        if (count($this->decoratedBatch) >= $this->threshold) {
            $this->decoratedBatch->flush();
        }

        return $this;
    }
}
