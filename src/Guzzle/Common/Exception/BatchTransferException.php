<?php

namespace Guzzle\Common\Exception;

use Guzzle\Common\GuzzleException;

/**
 * Exception thrown during a batch transfer
 */
class BatchTransferException extends \Exception implements GuzzleException
{
    /**
     * @param array      $batch     Batch being sent when the exception occurred
     * @param \Exception $exception Exception encountered
     */
    public function __construct(array $batch, \Exception $exception)
    {
        $this->batch = $batch;
        parent::__construct('Exception encountered while transferring batch', $exception->getCode(), $exception);
    }

    /**
     * Get the batch that we being sent when the exception occurred
     *
     * @return array
     */
    public function getBatch()
    {
        return $this->batch;
    }
}
