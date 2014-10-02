<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Exception\StateException;
use React\Promise\Deferred;

/**
 * Represents an HTTP response for a request that was cancelled.
 *
 * When the cancelled future is accessed, it will throw a state exception or
 * a custom exception if provided.
 */
class CancelledResponse extends FutureResponse
{
    public static function create(\Exception $e = null)
    {
        $e = $e ?: new StateException('Cannot access a cancelled response');
        $deferred = new Deferred();
        return new static(
            $deferred->promise(),
            function () use ($deferred, $e) {
                $deferred->reject($e);
            }
        );
    }

    public function cancelled()
    {
        return true;
    }
}
