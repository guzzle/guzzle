<?php
namespace GuzzleHttp\Exception;

use GuzzleHttp\Exception\Traits\HandlerContextAwareTrait;
use GuzzleHttp\Exception\Traits\RequestAwareTrait;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Exception thrown when a connection cannot be established.
 *
 * Note that no response is present for a ConnectException
 */
class ConnectException extends TransferException implements NetworkExceptionInterface
{
    use RequestAwareTrait, HandlerContextAwareTrait;

    public function __construct(
        string $message,
        RequestInterface $request,
        \Throwable $previous = null,
        array $handlerContext = []
    ) {
        parent::__construct($message, 0, $previous);
        $this->setRequest($request);
        $this->setHandlerContext($handlerContext);
    }

    /**
     * @deprecated 8.0.0
     */
    public function getResponse(): ?ResponseInterface
    {
        return null;
    }

    /**
     * @deprecated 8.0.0
     */
    public function hasResponse(): bool
    {
        return false;
    }
}
