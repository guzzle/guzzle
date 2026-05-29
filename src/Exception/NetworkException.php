<?php

namespace GuzzleHttp\Exception;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Base exception for network-related transfer failures.
 */
class NetworkException extends TransferException implements NetworkExceptionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var array
     */
    private $handlerContext;

    public function __construct(
        string $message,
        RequestInterface $request,
        ?\Throwable $previous = null,
        array $handlerContext = []
    ) {
        parent::__construct($message, 0, $previous);
        $this->request = $request;
        $this->handlerContext = $handlerContext;
    }

    /**
     * Get the request that caused the exception
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * Get contextual information about the error from the underlying handler.
     *
     * The contents of this array will vary depending on which handler you are
     * using. It may also be just an empty array. Relying on this data will
     * couple you to a specific handler, but can give more debug information
     * when needed.
     *
     * @deprecated since 7.11. Use TransferStats from the "on_stats" request
     *             option for handler context instead.
     */
    public function getHandlerContext(): array
    {
        \trigger_deprecation(
            'guzzlehttp/guzzle',
            '7.11',
            '%s is deprecated and will be removed in 8.0. Use TransferStats from the "on_stats" request option for handler context instead.',
            __METHOD__
        );

        return $this->handlerContext;
    }
}
