<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Decodes the chunked transfer coding of a response body read from the
 * unfiltered transport stream, with the framing rules libcurl applies.
 *
 * Each read returns the decoded bytes that are already available, waiting on
 * the transport only when there are none, so the transport's idle timeout
 * governs every wait. The body ends as soon as the last chunk and trailer
 * section have been decoded, without another transport read. A body that
 * ends early or has malformed framing fails the read once the bytes decoded
 * before the fault have been returned, and every later read fails the same
 * way.
 *
 * @internal
 */
final class ChunkedBodyStream implements StreamInterface
{
    use NonSerializableTrait;
    use StreamDecoratorTrait;

    private const READ_SIZE = 8192;

    private const MAX_SIZE_DIGITS = 17;

    private const MAX_TRAILER_LINE_LENGTH = 4093;

    private const MAX_TRAILER_SECTION_LENGTH = 307200;

    private const SIZE = 0;

    private const SIZE_LINE = 1;

    private const DATA = 2;

    private const DATA_END = 3;

    private const TRAILER = 4;

    private const TRAILER_CR = 5;

    private const FINAL_CR = 6;

    private const DONE = 7;

    private StreamInterface $stream;

    private string $buffer = '';

    private int $offset = 0;

    private int $state = self::SIZE;

    private string $digits = '';

    private int $remaining = 0;

    private string $line = '';

    private int $trailerLength = 0;

    private int $position = 0;

    private ?\RuntimeException $failure = null;

    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function read(int $length): string
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
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
        if ($this->state === self::DONE) {
            return $this->stream->detach();
        }

        if ($this->position > 0 || $this->buffer !== '' || $this->digits !== '' || $this->failure !== null) {
            throw new \RuntimeException('Cannot detach a chunked response body after reading from it');
        }

        $resource = $this->stream->detach();
        if (\is_resource($resource)) {
            // Nothing has been decoded yet, so PHP's own filter can take over.
            \stream_filter_append($resource, 'dechunk', \STREAM_FILTER_READ);
        }

        return $resource;
    }

    /**
     * Appends what the transport has available to the buffer, waiting for at
     * least one byte.
     */
    private function fill(): bool
    {
        // Before PHP 8.3 (8.4 for TLS), a read that starts with bytes PHP has
        // already buffered also waits for more from the network.
        $unread = $this->stream->getMetadata('unread_bytes');
        $data = $this->stream->read(\is_int($unread) && $unread > 0 ? \min($unread, self::READ_SIZE) : self::READ_SIZE);
        if ($data === '') {
            if (!$this->stream->eof()) {
                return false;
            }

            $this->failure = new \RuntimeException('Response body ended before the chunked body was complete');

            throw $this->failure;
        }

        $this->buffer = \substr($this->buffer, $this->offset).$data;
        $this->offset = 0;

        return true;
    }

    /**
     * Decodes up to $length bytes from the buffer, and any framing after them,
     * so the end of the body is noticed without another transport read.
     */
    private function decode(int $length): string
    {
        $data = '';

        try {
            while ($this->offset < \strlen($this->buffer) && $this->state !== self::DONE) {
                if ($this->state === self::DATA) {
                    $take = \min($this->remaining, $length - \strlen($data), \strlen($this->buffer) - $this->offset);
                    if ($take === 0) {
                        break;
                    }
                    $data .= \substr($this->buffer, $this->offset, $take);
                    $this->offset += $take;
                    $this->remaining -= $take;
                    if ($this->remaining === 0) {
                        $this->state = self::DATA_END;
                    }
                } elseif ($this->state === self::SIZE) {
                    $this->decodeSize();
                } elseif ($this->state === self::SIZE_LINE) {
                    $this->skipSizeLine();
                } elseif ($this->state === self::DATA_END) {
                    $this->decodeDataEnd();
                } else {
                    $this->decodeTrailer();
                }
            }
        } catch (\RuntimeException $e) {
            $this->failure = $e;
            if ($data === '') {
                throw $e;
            }
        }

        return $data;
    }

    private function decodeSize(): void
    {
        $count = \strspn($this->buffer, '0123456789abcdefABCDEF', $this->offset);
        $this->digits .= \substr($this->buffer, $this->offset, $count);
        $this->offset += $count;
        if (\strlen($this->digits) > self::MAX_SIZE_DIGITS) {
            throw new \RuntimeException('Invalid chunked response body: invalid chunk size');
        }
        if ($this->offset === \strlen($this->buffer)) {
            return;
        }
        if ($this->digits === '') {
            throw new \RuntimeException('Invalid chunked response body: invalid chunk size');
        }

        $significant = \ltrim($this->digits, '0');
        if (\strlen($significant) > 16 || (\strlen($significant) === 16 && $significant[0] > '7')) {
            throw new \RuntimeException('Invalid chunked response body: invalid chunk size');
        }

        $size = \hexdec($this->digits);
        if (!\is_int($size)) {
            throw new \OverflowException('Chunk size exceeds the maximum integer size supported on this platform');
        }

        $this->digits = '';
        $this->remaining = $size;
        $this->state = self::SIZE_LINE;
    }

    private function skipSizeLine(): void
    {
        // Chunk extensions, and anything else up to the line feed, are ignored
        // the way libcurl ignores them.
        $end = \strpos($this->buffer, "\n", $this->offset);
        if ($end === false) {
            $this->offset = \strlen($this->buffer);

            return;
        }

        $this->offset = $end + 1;
        $this->state = $this->remaining === 0 ? self::TRAILER : self::DATA;
    }

    private function decodeDataEnd(): void
    {
        $this->offset += \strspn($this->buffer, "\r", $this->offset);
        if ($this->offset === \strlen($this->buffer)) {
            return;
        }
        if ($this->buffer[$this->offset] !== "\n") {
            throw new \RuntimeException('Invalid chunked response body: missing CRLF after chunk data');
        }

        ++$this->offset;
        $this->state = self::SIZE;
    }

    private function decodeTrailer(): void
    {
        if ($this->state !== self::TRAILER) {
            if ($this->buffer[$this->offset] !== "\n") {
                throw new \RuntimeException('Invalid chunked response body: invalid trailer section');
            }

            ++$this->offset;
            $this->state = $this->state === self::FINAL_CR ? self::DONE : self::TRAILER;

            return;
        }

        $count = \strcspn($this->buffer, "\r\n", $this->offset);
        $this->line .= \substr($this->buffer, $this->offset, $count);
        $this->offset += $count;
        $this->trailerLength += $count;
        if (\strlen($this->line) > self::MAX_TRAILER_LINE_LENGTH || $this->trailerLength > self::MAX_TRAILER_SECTION_LENGTH) {
            throw new \RuntimeException('Invalid chunked response body: invalid trailer section');
        }
        if ($this->offset === \strlen($this->buffer)) {
            return;
        }

        $carriageReturn = $this->buffer[$this->offset] === "\r";
        ++$this->offset;
        if ($this->line === '') {
            $this->state = $carriageReturn ? self::FINAL_CR : self::DONE;

            return;
        }

        if (\strpos($this->line, "\0") !== false || \strpos($this->line, ':') === false || $this->line[0] === ' ' || $this->line[0] === "\t") {
            throw new \RuntimeException('Invalid chunked response body: invalid trailer section');
        }

        $this->line = '';
        if ($carriageReturn) {
            $this->state = self::TRAILER_CR;
        }
    }
}
