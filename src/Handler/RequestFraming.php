<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use Psr\Http\Message\RequestInterface;

/**
 * Validates and normalizes request framing before a handler performs I/O.
 *
 * Body size describes the bytes available under handler rewind semantics.
 * Content-Length is the selected transport boundary and may remain unknown.
 *
 * @internal
 */
final class RequestFraming
{
    use NonSerializableTrait;

    public RequestInterface $request;

    /**
     * Number of bytes available after applying handler rewind semantics, if
     * known.
     */
    public ?int $bodySize;

    /** Canonical Content-Length boundary selected for transport, if any. */
    public ?int $contentLength;

    private function __construct(RequestInterface $request, ?int $bodySize, ?int $contentLength)
    {
        $this->request = $request;
        $this->bodySize = $bodySize;
        $this->contentLength = $contentLength;
    }

    /**
     * Finalizes request framing for a handler dispatch.
     *
     * When $sendBody is false, body-dependent validation and size probing are
     * skipped. Content-Length is still canonicalized and Transfer-Encoding is
     * removed.
     *
     * @throws RequestException when framing or body metadata is unsafe
     */
    public static function analyze(
        #[\SensitiveParameter]
        RequestInterface $request,
        bool $sendBody = true
    ): self {
        try {
            $length = HeaderProcessor::parseContentLength($request->getHeader('Content-Length'));
        } catch (\RuntimeException $e) {
            throw new RequestException('Invalid Content-Length request header: '.$e->getMessage(), $request, 0, $e);
        }

        try {
            HeaderProcessor::assertContentLengthWithinPlatformLimit($length);
        } catch (\OverflowException $e) {
            throw new RequestException($e->getMessage(), $request, 0, $e);
        }

        $contentLength = HeaderProcessor::contentLengthToInt($length);
        if ($length !== null) {
            $request = $request->withHeader('Content-Length', $length);
        }

        if (!$sendBody) {
            return new self($request->withoutHeader('Transfer-Encoding'), null, $contentLength);
        }

        $transferEncoding = $request->getHeader('Transfer-Encoding');
        if ($length !== null && $transferEncoding !== []) {
            throw new RequestException(
                'A request must not contain both Content-Length and Transfer-Encoding',
                $request
            );
        }

        if ($transferEncoding !== []) {
            $tokens = [];
            foreach ($transferEncoding as $value) {
                foreach (\explode(',', $value) as $part) {
                    $tokens[] = \trim($part, " \t");
                }
            }

            if (\count($tokens) !== 1 || !Psr7\Utils::caselessEquals($tokens[0], 'chunked') || $request->getProtocolVersion() !== '1.1') {
                throw new RequestException('Unsupported Transfer-Encoding request header', $request);
            }
        }

        $bodySize = self::bodySize($request);
        if ($bodySize !== null && $contentLength !== null && $bodySize !== $contentLength) {
            throw new RequestException('Content-Length does not match the request body size', $request);
        }

        if ($bodySize === null && $contentLength === null && $request->getProtocolVersion() === '1.0') {
            throw new RequestException('An unknown-size HTTP/1.0 request body requires Content-Length', $request);
        }

        if ($length === null && $bodySize !== null && ($bodySize > 0 || \in_array($request->getMethod(), ['PUT', 'POST'], true))) {
            $contentLength = $bodySize;
            $request = $request->withHeader('Content-Length', (string) $bodySize);
        }

        return new self($request->withoutHeader('Transfer-Encoding'), $bodySize, $contentLength);
    }

    /**
     * Returns the number of bytes available for dispatch, or null when unknown.
     *
     * Seekable bodies use their total size because handlers rewind them.
     * Positioned non-seekable bodies use the total size minus their current
     * position.
     *
     * @throws RequestException when body metadata cannot be read safely
     */
    public static function bodySize(
        #[\SensitiveParameter]
        RequestInterface $request
    ): ?int {
        $body = $request->getBody();

        try {
            $size = $body->getSize();
            if ($size === null) {
                return null;
            }

            if ($size < 0) {
                throw new \RuntimeException('Request body size must not be negative');
            }

            $seekable = $body->isSeekable();
        } catch (\Exception $e) {
            throw self::bodyException(
                $request,
                $e,
                'Timed out while determining the request body size',
                'Failed to determine the request body size'
            );
        }

        if ($seekable) {
            return $size;
        }

        try {
            $position = $body->tell();
        } catch (\RuntimeException $e) {
            return null;
        }

        if ($position < 0 || $position > $size) {
            throw new RequestException('The request body position is outside the stream size', $request);
        }

        return $size - $position;
    }

    /**
     * Materializes the body, stopping at the selected Content-Length when
     * present.
     *
     * @throws RequestException when the body cannot be fully rewound or read
     */
    public function materialize(): string
    {
        $body = $this->request->getBody();

        if ($this->contentLength === null) {
            try {
                return (string) $body;
            } catch (\Exception $e) {
                throw self::bodyException(
                    $this->request,
                    $e,
                    'Timed out while reading the request body',
                    'Failed to read the request body'
                );
            }
        }

        if ($this->contentLength === 0) {
            return '';
        }

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
        } catch (\Exception $e) {
            throw self::bodyException(
                $this->request,
                $e,
                'Timed out while rewinding the request body',
                'Failed to rewind the request body'
            );
        }

        $contents = '';
        $remaining = $this->contentLength;
        while ($remaining > 0) {
            $limit = \min(8192, $remaining);

            try {
                $chunk = $body->read($limit);
            } catch (\Exception $e) {
                throw self::bodyException(
                    $this->request,
                    $e,
                    'Timed out while reading the request body',
                    'Failed to read the request body'
                );
            }

            if ($chunk === '') {
                throw new RequestException(
                    'Request body ended before the declared Content-Length was reached',
                    $this->request
                );
            }

            if (\strlen($chunk) > $limit) {
                throw new RequestException('Request body stream returned more bytes than requested', $this->request);
            }

            $contents .= $chunk;
            $remaining -= \strlen($chunk);
        }

        return $contents;
    }

    private static function bodyException(
        #[\SensitiveParameter]
        RequestInterface $request,
        #[\SensitiveParameter]
        \Exception $exception,
        string $timeoutMessage,
        string $fallbackMessage
    ): RequestException {
        $message = $exception instanceof TimeoutException
            ? $timeoutMessage
            : ($exception->getMessage() !== '' ? $exception->getMessage() : $fallbackMessage);

        return new RequestException($message, $request, 0, $exception);
    }
}
