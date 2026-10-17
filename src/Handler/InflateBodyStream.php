<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\StreamWrapper;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Inflates a gzip or zlib encoded response body as the encoded bytes arrive.
 *
 * Unlike the zlib.inflate stream filter, a read returns the bytes decoded so
 * far instead of waiting to fill the requested length, and invalid compressed
 * data always fails the read. Only the first compressed stream is decoded;
 * later bytes are read and discarded, as the filter does.
 *
 * @internal
 */
final class InflateBodyStream implements StreamInterface
{
    use NonSerializableTrait;

    private const CHUNK_SIZE = 8192;

    private const INVALID_DATA = 'Unable to decode the response body: invalid gzip or deflate data';

    private ?StreamInterface $stream;

    /** @var \InflateContext|resource|null */
    private $context;

    private string $header = '';

    private string $buffer = '';

    private int $offset = 0;

    private int $position = 0;

    private bool $finished = false;

    private ?string $error = null;

    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function getContents(): string
    {
        return Utils::copyToString($this);
    }

    public function close(): void
    {
        $stream = $this->stream;
        $this->release();

        if ($stream !== null) {
            $stream->close();
        }
    }

    public function detach()
    {
        if ($this->stream === null) {
            return null;
        }

        // Hand the decoder over to a resource that keeps yielding decoded
        // bytes, like the filtered resource InflateStream detaches.
        $stream = clone $this;
        $this->release();

        return StreamWrapper::getResource($stream);
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        $this->attached();

        return $this->position;
    }

    public function eof(): bool
    {
        $stream = $this->attached();

        // Ending the body on a decoding failure also keeps PSR-7 copy helpers
        // from reporting it as a timeout when Windows leaves timed_out set.
        return $this->error !== null || ($this->offset === \strlen($this->buffer) && $stream->eof());
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Stream is not seekable');
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
        throw new \RuntimeException('Cannot write to a non-writable stream');
    }

    public function isReadable(): bool
    {
        return $this->stream !== null && $this->stream->isReadable();
    }

    public function read(int $length): string
    {
        $stream = $this->attached();

        if ($length < 0) {
            throw new \RuntimeException('Length parameter cannot be negative');
        }

        if ($length === 0) {
            return '';
        }

        while ($this->offset === \strlen($this->buffer)) {
            if ($this->error !== null) {
                throw new \RuntimeException($this->error);
            }

            $encoded = $stream->read(self::CHUNK_SIZE);
            if ($encoded === '') {
                return '';
            }

            if (!$this->finished) {
                $this->buffer = $this->inflate($encoded);
                $this->offset = 0;
            }
        }

        // Slice by offset, since a single inflate_add() call can return
        // megabytes of highly compressible data.
        $data = (string) \substr($this->buffer, $this->offset, $length);
        $this->offset += \strlen($data);
        $this->position = TransferByteCounter::add(
            $this->position,
            \strlen($data),
            'Response body exceeds the maximum integer size supported on this platform'
        );

        return $data;
    }

    /**
     * @return mixed
     */
    public function getMetadata(?string $key = null)
    {
        if ($this->stream === null) {
            return $key === null ? [] : null;
        }

        return $this->stream->getMetadata($key);
    }

    private function inflate(string $encoded): string
    {
        if ($this->context === null) {
            $this->header .= $encoded;
            if (\strlen($this->header) < 2) {
                return '';
            }

            $encoded = $this->header;
            $this->header = '';

            if (!\function_exists('inflate_init')) {
                throw $this->fail('Unable to decode the response body: the zlib extension is not loaded');
            }

            // inflate_init() has no automatic header detection, so pick the
            // format the way zlib does for the window bits 15 + 32 that the
            // zlib.inflate filter is given.
            $context = \inflate_init(\strncmp($encoded, "\x1f\x8b", 2) === 0 ? \ZLIB_ENCODING_GZIP : \ZLIB_ENCODING_DEFLATE);
            if ($context === false) {
                throw $this->fail(self::INVALID_DATA);
            }

            $this->context = $context;
        }

        $decoded = @\inflate_add($this->context, $encoded);
        if ($decoded === false) {
            throw $this->fail(self::INVALID_DATA);
        }

        $this->finished = \inflate_get_status($this->context) === \ZLIB_STREAM_END;

        return $decoded;
    }

    private function attached(): StreamInterface
    {
        if ($this->stream === null) {
            throw new \RuntimeException('Stream is detached');
        }

        return $this->stream;
    }

    private function release(): void
    {
        $this->stream = null;
        $this->context = null;
        $this->buffer = '';
        $this->offset = 0;
    }

    private function fail(string $message): \RuntimeException
    {
        $this->error = $message;

        return new \RuntimeException($message);
    }
}
