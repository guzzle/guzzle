<?php

namespace Guzzle\Common\Batch;

/**
 * BatchInterface decorator used to buffer exceptions when an exception occurs
 * during a transfer.  The exceptions can then later be processed after a batch
 * flush has completed.
 */
class ExceptionBufferingBatch extends AbstractBatchDecorator
{
    /**
     * @var array Exceptions encountered
     */
    protected $exceptions = array();

    /**
     * {@inheritdoc}
     */
    public function add($item)
    {
        try {
            $this->decoratedBatch->add($item);
        } catch (\Exception $e) {
            $this->exceptions[] = $e;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function flush()
    {
        $items = array();

        while (count($this->decoratedBatch)) {
            try {
                $items = array_merge($items, $this->decoratedBatch->flush());
            } catch (\Exception $e) {
                $this->exceptions[] = $e;
            }
        }

        return $items;
    }

    /**
     * Get the buffered exceptions
     *
     * @return array
     */
    public function getExceptions()
    {
        return $this->exceptions;
    }

    /**
     * Clear the buffered exceptions
     */
    public function clearExceptions()
    {
        $this->exceptions = array();
    }
}
