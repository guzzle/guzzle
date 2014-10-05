<?php
namespace GuzzleHttp\Exception;

use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Ring\Exception\CancelledException;

/**
 * A request exception thrown when accessing a cancelled future response.
 */
class CancelledRequestException extends RequestException implements CancelledException
{
    /**
     * Throws an exception that marks the future as cancelled, preventing the
     * end event from throwing an exception.
     *
     * @param RequestInterface  $request  Request being cancelled.
     * @param ResponseInterface $response Optional response of the request.
     * @param \Exception        $previous Optional previous exception
     *
     * @return self
     */
    public static function fromTrans(
        RequestInterface $request,
        ResponseInterface $response = null,
        \Exception $previous = null
    ) {
        return new self('Cancelled future', $request, $response, $previous);
    }
}
