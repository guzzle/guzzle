<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

use GuzzleHttp\BodySummarizer;
use GuzzleHttp\BodySummarizerInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP Request exception
 */
class RequestException extends TransferException implements RequestExceptionInterface
{
    private RequestInterface $request;

    private array $handlerContext;

    public function __construct(
        string $message,
        RequestInterface $request,
        int $code = 0,
        ?\Throwable $previous = null,
        array $handlerContext = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->request = $request;
        $this->handlerContext = $handlerContext;
    }

    /**
     * Factory method to create a new exception with a normalized error message
     *
     * @param RequestInterface             $request        Request sent
     * @param ResponseInterface|null       $response       Response received, if any
     * @param \Throwable|null              $previous       Previous exception
     * @param array                        $handlerContext Optional handler context
     * @param BodySummarizerInterface|null $bodySummarizer Optional body summarizer
     */
    public static function create(
        RequestInterface $request,
        ?ResponseInterface $response = null,
        ?\Throwable $previous = null,
        array $handlerContext = [],
        ?BodySummarizerInterface $bodySummarizer = null
    ): self {
        if (!$response) {
            return new self(
                'Error completing request',
                $request,
                0,
                $previous,
                $handlerContext
            );
        }

        $level = (int) \floor($response->getStatusCode() / 100);
        if ($level === 4) {
            $label = 'Client error';
        } elseif ($level === 5) {
            $label = 'Server error';
        } else {
            $label = 'Unsuccessful request';
        }

        $uri = \GuzzleHttp\Psr7\Utils::redactUserInfo($request->getUri());

        // Client Error: `GET /` resulted in a `404 Not Found` response:
        // <html> ... (truncated)
        $message = \sprintf(
            '%s: `%s %s` resulted in a `%s %s` response',
            $label,
            $request->getMethod(),
            $uri->__toString(),
            $response->getStatusCode(),
            $response->getReasonPhrase()
        );

        $summary = ($bodySummarizer ?? new BodySummarizer())->summarize($response);

        if ($summary !== null) {
            $message .= ":\n{$summary}\n";
        }

        if ($level === 4) {
            return new ClientException($message, $request, $response, $previous, $handlerContext);
        }

        if ($level === 5) {
            return new ServerException($message, $request, $response, $previous, $handlerContext);
        }

        return new ResponseException($message, $request, $response, $previous, $handlerContext);
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
     */
    public function getHandlerContext(): array
    {
        return $this->handlerContext;
    }
}
