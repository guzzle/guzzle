<?php
namespace GuzzleHttp\Message;

use GuzzleHttp\Exception\RequestException;
use React\Promise\RejectedPromise;

/**
 * Future response that has been cancelled.
 */
class CancelledFutureResponse extends FutureResponse
{
    /**
     * Given an exception, return a cancelled future response that is
     * associated with the exception.
     *
     * @param RequestException $e
     *
     * @return FutureResponse
     */
    public static function fromException(RequestException $e)
    {
        return new self(new RejectedPromise($e));
    }

    public function realized()
    {
        return true;
    }

    public function cancelled()
    {
        return true;
    }
}
