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
 * data always fails the read. As with libcurl, data after the compressed
 * stream, beyond up to 4 bytes after raw deflate data, fails the read that
 * follows the last decoded byte.
 *
 * @internal
 */
final class InflateBodyStream implements StreamInterface
{
    use NonSerializableTrait;

    private const CHUNK_SIZE = 8192;

    private const INVALID_DATA = 'Unable to decode the response body: invalid gzip or deflate data';

    private const TRAILING_DATA = 'Unable to decode the response body: unexpected data after the compressed stream';

    private ?StreamInterface $stream;

    private bool $deflate;

    /** @var \InflateContext|resource|null */
    private $context;

    private string $header = '';

    private string $buffer = '';

    private int $offset = 0;

    private int $position = 0;

    private bool $finished = false;

    private int $trailingAllowance = 0;

    private ?\RuntimeException $error = null;

    /**
     * @param bool $deflate Whether the body is labelled "deflate", which also
     *                      accepts raw deflate data like libcurl does.
     */
    public function __construct(StreamInterface $stream, bool $deflate = false)
    {
        $this->stream = $stream;
        $this->deflate = $deflate;
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

        // A failed body never ends, so resource readers see the failure
        // instead of a clean end of the stream.
        return $this->error === null && $this->offset === \strlen($this->buffer) && $stream->eof();
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
                throw $this->error;
            }

            $encoded = $stream->read(self::CHUNK_SIZE);
            if ($encoded === '') {
                return '';
            }

            if ($this->finished) {
                if ($this->allowTrailing(\strlen($encoded))) {
                    continue;
                }

                throw $this->fail(self::TRAILING_DATA);
            }

            $this->buffer = $this->inflate($encoded);
            $this->offset = 0;
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

        // A decoding failure is not a timeout, even when Windows leaves the
        // transport's timed_out flag set after a read drains PHP's buffer.
        if ($key === 'timed_out' && $this->error !== null) {
            return false;
        }

        $metadata = $this->stream->getMetadata($key);
        if ($key === null && $this->error !== null && \is_array($metadata)) {
            $metadata['timed_out'] = false;
        }

        return $metadata;
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

            $encoding = $this->encoding($encoded);
            $context = \inflate_init($encoding);
            if ($context === false) {
                throw $this->fail(self::INVALID_DATA);
            }

            $this->context = $context;

            // Like libcurl, accept up to 4 unknown bytes after raw deflate
            // data, such as the checksum of a zlib stream missing its header.
            $this->trailingAllowance = $encoding === \ZLIB_ENCODING_RAW ? 4 : 0;
        }

        $readBefore = \inflate_get_read_len($this->context);

        // Application error handlers also see @-suppressed warnings, so keep
        // the warning inflate_add() emits before returning false from them.
        \set_error_handler(static function (): bool {
            return true;
        });

        try {
            $decoded = \inflate_add($this->context, $encoded);
        } finally {
            \restore_error_handler();
        }

        if ($decoded === false) {
            throw $this->fail(self::INVALID_DATA);
        }

        $this->finished = \inflate_get_status($this->context) === \ZLIB_STREAM_END;
        if ($this->finished) {
            $consumed = \inflate_get_read_len($this->context) - $readBefore;
            if ($consumed < 0) {
                // zlib counts input in an unsigned long, which wraps at 4 GiB
                // on Windows and 32-bit builds.
                $consumed += 0x100000000;
            }

            if (!$this->allowTrailing(\strlen($encoded) - (int) $consumed)) {
                // Fail on the next read, after the decoded end of the body.
                $this->fail(self::TRAILING_DATA);
            }
        }

        return $decoded;
    }

    private function encoding(string $header): int
    {
        // inflate_init() has no automatic header detection, so pick the
        // format the way zlib does for the window bits 15 + 32 that the
        // zlib.inflate filter is given.
        if (\strncmp($header, "\x1f\x8b", 2) === 0) {
            return \ZLIB_ENCODING_GZIP;
        }

        // Like libcurl, treat deflate data without a valid zlib header as raw.
        $cmf = \ord($header[0]);
        if ($this->deflate && (($cmf & 0x0F) !== 8 || $cmf >> 4 > 7 || (($cmf << 8) | \ord($header[1])) % 31 !== 0)) {
            return \ZLIB_ENCODING_RAW;
        }

        return \ZLIB_ENCODING_DEFLATE;
    }

    private function allowTrailing(int $length): bool
    {
        if ($length > $this->trailingAllowance) {
            return false;
        }

        $this->trailingAllowance -= $length;

        return true;
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
        return $this->error = new \RuntimeException($message);
    }
}
