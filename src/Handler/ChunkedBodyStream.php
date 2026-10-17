<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Decodes the chunked transfer coding of a response body read from the
 * unfiltered transport stream.
 *
 * Each read returns the decoded bytes that are already available, waiting on
 * the transport only when none are, so the transport's idle timeout applies
 * to every wait. A body that ends before its last chunk, or whose framing is
 * malformed, fails the read instead of yielding truncated or raw bytes.
 *
 * @internal
 */
final class ChunkedBodyStream implements StreamInterface
{
    use NonSerializableTrait;
    use StreamDecoratorTrait;

    private const READ_SIZE = 8192;

    private const MAX_LINE_LENGTH = 8192;

    private const MAX_TRAILER_LENGTH = 65536;

    private const SIZE = 0;

    private const DATA = 1;

    private const DATA_END = 2;

    private const TRAILER = 3;

    private const DONE = 4;

    private StreamInterface $stream;

    private string $buffer = '';

    private int $offset = 0;

    private int $state = self::SIZE;

    private int $remaining = 0;

    private int $trailerLength = 0;

    private int $position = 0;

    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \RuntimeException('Length parameter cannot be negative');
        }

        do {
            $data = $this->decode($length);
        } while ($data === '' && $length > 0 && !$this->eof() && $this->fill());

        $this->position += \strlen($data);

        return $data;
    }

    public function eof(): bool
    {
        return $this->state === self::DONE;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Cannot seek a chunked response body');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Cannot write to a chunked response body');
    }

    public function detach()
    {
        $resource = $this->stream->detach();
        if (\is_resource($resource) && $this->state !== self::DONE) {
            if ($this->position > 0 || $this->buffer !== '' || $this->state !== self::SIZE) {
                \fclose($resource);

                throw new \RuntimeException('Cannot detach a partially decoded chunked response body');
            }

            // Nothing has been decoded yet, so PHP's own filter can take over.
            \stream_filter_append($resource, 'dechunk', \STREAM_FILTER_READ);
        }

        return $resource;
    }

    /**
     * Fills the buffer with what the transport has available, waiting for at
     * least one byte.
     */
    private function fill(): bool
    {
        // Before PHP 8.3 (8.4 for TLS), a read that starts with bytes PHP has
        // already buffered also waits for more from the network.
        $unread = $this->stream->getMetadata('unread_bytes');
        $data = $this->stream->read(\is_int($unread) && $unread > 0 ? \min($unread, self::READ_SIZE) : self::READ_SIZE);
        if ($data === '') {
            if ($this->stream->eof()) {
                throw new \RuntimeException('Chunked response body ended before the last chunk');
            }

            return false;
        }

        if ($this->offset > 0) {
            $this->buffer = (string) \substr($this->buffer, $this->offset);
            $this->offset = 0;
        }
        $this->buffer .= $data;

        return true;
    }

    private function decode(int $length): string
    {
        $data = '';
        $available = \strlen($this->buffer) - $this->offset;

        while ($available > 0 && $this->state !== self::DONE) {
            if ($this->state === self::DATA) {
                $take = \min($this->remaining, $length - \strlen($data), $available);
                $data .= \substr($this->buffer, $this->offset, $take);
                $this->offset += $take;
                $available -= $take;
                $this->remaining -= $take;
                if ($this->remaining === 0) {
                    $this->state = self::DATA_END;
                }
                if (\strlen($data) === $length) {
                    break;
                }
            } elseif ($this->state === self::DATA_END) {
                if ($this->buffer[$this->offset] === "\n") {
                    $consumed = 1;
                } elseif ($available < 2) {
                    break;
                } elseif ($this->buffer[$this->offset + 1] === "\n" && $this->buffer[$this->offset] === "\r") {
                    $consumed = 2;
                } else {
                    throw new \RuntimeException('Invalid chunk terminator in chunked response body');
                }
                $this->offset += $consumed;
                $available -= $consumed;
                $this->state = self::SIZE;
            } else {
                $end = \strpos($this->buffer, "\n", $this->offset);
                if ($end === false) {
                    if ($available > self::MAX_LINE_LENGTH) {
                        throw new \RuntimeException('Chunked response body line is too long');
                    }
                    break;
                }

                $lineLength = $end - $this->offset;
                if ($lineLength > 0 && $this->buffer[$end - 1] === "\r") {
                    --$lineLength;
                }

                if ($this->state === self::SIZE) {
                    $this->startChunk($this->offset, $lineLength);
                } elseif ($lineLength === 0) {
                    $this->state = self::DONE;
                } elseif (($this->trailerLength += $end + 1 - $this->offset) > self::MAX_TRAILER_LENGTH) {
                    throw new \RuntimeException('Chunked response body trailers are too long');
                }

                $available -= $end + 1 - $this->offset;
                $this->offset = $end + 1;
            }
        }

        return $data;
    }

    private function startChunk(int $offset, int $length): void
    {
        $digits = \strspn($this->buffer, '0123456789abcdefABCDEF', $offset, $length);
        if ($digits === 0 || $digits > 15) {
            throw new \RuntimeException('Invalid chunk size in chunked response body');
        }

        if ($digits < $length && \preg_match('/^[\t ]*(?:;.*)?$/sD', \substr($this->buffer, $offset + $digits, $length - $digits)) !== 1) {
            throw new \RuntimeException('Invalid chunk size in chunked response body');
        }

        $size = \hexdec(\substr($this->buffer, $offset, $digits));
        if (!\is_int($size)) {
            throw new \RuntimeException('Chunk size in chunked response body is too large');
        }

        if ($size === 0) {
            $this->state = self::TRAILER;
        } else {
            $this->remaining = $size;
            $this->state = self::DATA;
        }
    }
}
