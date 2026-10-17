<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Ends a streamed response body at its declared Content-Length.
 *
 * Reads never consume bytes beyond the response message, and the body reaches
 * EOF without waiting for the server to close the connection. A transport that
 * ends before the declared length fails the read instead of looking like a
 * complete body.
 *
 * @internal
 */
final class DeclaredLengthStream implements StreamInterface
{
    use NonSerializableTrait;
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $remaining;

    public function __construct(StreamInterface $stream, int $declaredLength)
    {
        $this->stream = $stream;
        $this->remaining = $declaredLength;
    }

    public function eof(): bool
    {
        return $this->remaining === 0;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Cannot seek a response body bounded by its declared Content-Length');
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \RuntimeException('Length parameter cannot be negative');
        }

        if ($length === 0 || $this->remaining === 0) {
            return '';
        }

        $data = $this->stream->read(\min($length, $this->remaining));
        if ($data === '' && $this->stream->eof()) {
            throw new \RuntimeException('Response body ended before the declared Content-Length was reached');
        }

        $this->remaining -= \strlen($data);

        return $data;
    }
}
