<?php

namespace Guzzle\Common\Batch;

/**
 * Batch transfer strategy where transfer logic can be defined via a Closure.
 * This class is to be used with {@see Guzzle\Common\Batch\BatchInterface}
 */
class BatchClosureTransfer implements BatchTransferInterface
{
    /**
     * @var Closure A closure that performs the transfer
     */
    protected $closure;

    /**
     * Constructor used to specify the closure for performing the transfer
     *
     * @param Closure $closure A closure that performs the transfer. The closure
     *                         should have a single argument (array $batch)
     *                         identical to the BatchTransferInterface::transfer
     *                         method.
     */
    public function __construct(\Closure $closure)
    {
        $this->closure = $closure;
    }

    /**
     * {@inheritDoc}
     */
    public function transfer(array $batch)
    {
        if (empty($batch)) {
            return;
        }

        call_user_func($this->closure, $batch);
    }
}
