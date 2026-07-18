<?php

declare(strict_types=1);

namespace GuzzleHttp\Exception;

use GuzzleHttp\BodySummarizer;
use GuzzleHttp\BodySummarizerInterface;
use GuzzleHttp\Psr7\DiagnosticValue;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Base exception for request failures associated with a request.
 */
class RequestException extends TransferException implements RequestExceptionInterface
{
    public function __construct(
        string $message,
        RequestInterface $request,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $request, $code, $previous);
    }

    /**
     * Factory method to create a new exception with a normalized error message
     *
     * @param RequestInterface             $request        Request sent
     * @param ResponseInterface|null       $response       Response received, if any
     * @param \Throwable|null              $previous       Previous exception
     * @param BodySummarizerInterface|null $bodySummarizer Optional body summarizer
     */
    public static function create(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        ?ResponseInterface $response = null,
        #[\SensitiveParameter]
        ?\Throwable $previous = null,
        ?BodySummarizerInterface $bodySummarizer = null
    ): self {
        if (!$response) {
            return new self(
                'Error completing request',
                $request,
                0,
                $previous
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

        // Client error: `GET /` resulted in a `404 Not Found` response: <html> ... (truncated)
        $message = \sprintf(
            '%s: `%s %s` resulted in a `%s %s` response',
            $label,
            DiagnosticValue::escape($request->getMethod()),
            DiagnosticValue::escape($uri->__toString()),
            $response->getStatusCode(),
            DiagnosticValue::escape($response->getReasonPhrase())
        );

        $summary = ($bodySummarizer ?? new BodySummarizer())->summarize($response);

        if ($summary !== null) {
            $message .= \sprintf(': %s', DiagnosticValue::escape($summary));
        }

        if ($level === 4) {
            return new ClientException($message, $request, $response, $previous);
        }

        if ($level === 5) {
            return new ServerException($message, $request, $response, $previous);
        }

        return new ResponseException($message, $request, $response, $previous);
    }
}
