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

    private ?\RuntimeException $failure = null;

    public function __construct(StreamInterface $stream, int $declaredLength)
    {
        $this->stream = $stream;
        $this->remaining = $declaredLength;
    }

    public function __clone()
    {
        // Detaching a clone must not detach this body's transport.
        $this->stream = clone $this->stream;
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

    /**
     * @return mixed
     */
    public function getMetadata(?string $key = null)
    {
        // A failed body has not timed out, whatever a stale flag on the
        // transport says, so callers report the failure itself.
        if ($this->failure !== null && $key === 'timed_out') {
            return false;
        }

        $metadata = $this->stream->getMetadata($key);
        if ($this->failure !== null && $key === null && \is_array($metadata)) {
            $metadata['timed_out'] = false;
        }

        return $metadata;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \RuntimeException('Length parameter cannot be negative');
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($length === 0 || $this->remaining === 0) {
            return '';
        }

        $data = $this->stream->read(\min($length, $this->remaining));
        if ($data === '' && $this->stream->eof()) {
            $this->failure = new \RuntimeException('Response body ended before the declared Content-Length was reached');

            throw $this->failure;
        }

        $this->remaining -= \strlen($data);

        return $data;
    }
}
