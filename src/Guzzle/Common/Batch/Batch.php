<?php

namespace Guzzle\Common\Batch;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Exception\BatchTransferException;

/**
 * Abstract batch
 */
class Batch implements BatchInterface
{
    /**
     * @var \SplQueue Queue of items in the queue
     */
    protected $queue;

    /**
     * @var BatchTransferInterface
     */
    protected $transferStrategy;

    /**
     * @var BatchDivisorInterface
     */
    protected $divisionStrategy;

    /**
     * @param BatchTransferInterface $transferStrategy Strategy used to transfer items
     */
    public function __construct(BatchTransferInterface $transferStrategy, BatchDivisorInterface $divisionStrategy)
    {
        $this->transferStrategy = $transferStrategy;
        $this->divisionStrategy = $divisionStrategy;
        $this->queue = new \SplQueue();
        $this->queue->setIteratorMode(\SplQueue::IT_MODE_DELETE);
    }

    /**
     * {@inheritdoc}
     */
    public function add($item)
    {
        $this->queue->enqueue($item);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $batches = (array) $this->divisionStrategy->createBatches($this->queue);
        foreach ($batches as $index => $batch) {
            try {
                $this->transferStrategy->transfer($batch);
            } catch (\Exception $e) {
                // Recover from a transfer exception by adding the items that
                // have not been transferred back on to the batch
                for ($i = $index + 1, $total = count($batches); $i < $total; $i++) {
                    foreach ($batches[$i] as $item) {
                        $this->add($item);
                    }
                }

                throw new BatchTransferException($batch, $e);
            }
        }

        $items = array();
        foreach ($batches as $batch) {
            $items = array_merge($items, $batch);
        }

        return $items;
    }

    /**
     * Get the total number of items in the queue
     *
     * @return int
     */
    public function count()
    {
        return count($this->queue);
    }
}
