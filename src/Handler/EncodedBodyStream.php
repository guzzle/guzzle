<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Tracks encoded response bytes beneath a content-decoding stream.
 *
 * Reads are bounded by a positive, representable Content-Length so a decoder
 * cannot consume bytes beyond the response message boundary.
 *
 * @internal
 */
final class EncodedBodyStream implements StreamInterface
{
    use NonSerializableTrait;
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private ?int $limit;

    private int $bytesRead = 0;

    public function __construct(StreamInterface $stream, string $declaredLength)
    {
        $this->stream = $stream;

        $limit = HeaderProcessor::contentLengthToInt($declaredLength);
        $this->limit = $limit !== null && $limit > 0 ? $limit : null;
    }

    public function eof(): bool
    {
        return ($this->limit !== null && $this->bytesRead >= $this->limit)
            || $this->stream->eof();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Cannot seek a stream while tracking encoded response bytes');
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \RuntimeException('Length parameter cannot be negative');
        }

        if ($this->limit !== null) {
            $remaining = $this->limit - $this->bytesRead;
            if ($remaining === 0) {
                return '';
            }

            $length = \min($length, $remaining);
        }

        $data = $this->stream->read($length);
        $this->bytesRead = TransferByteCounter::add(
            $this->bytesRead,
            \strlen($data),
            'Response body exceeds the maximum integer size supported on this platform'
        );

        return $data;
    }

    public function getBytesRead(): int
    {
        return $this->bytesRead;
    }
}
