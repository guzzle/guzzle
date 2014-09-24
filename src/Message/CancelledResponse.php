<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Exception\StateException;

/**
 * Represents an HTTP response for a request that was cancelled.
 *
 * When the cancelled future is accessed, it will throw a state exception or
 * a custom exception if provided.
 */
class CancelledResponse extends FutureResponse
{
    public function __construct(\Exception $e = null)
    {
        $e = $e ?: new StateException('Cannot access a cancelled response');
        parent::__construct(function () use ($e) {
            throw $e;
        });
    }

    public function cancelled()
    {
        return true;
    }
}
