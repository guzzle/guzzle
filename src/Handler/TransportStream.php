<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Decorates the stream that wraps a response transport so a read never waits
 * on the network while PHP still holds bytes it has already received.
 *
 * Before PHP 8.3, a blocking read longer than the bytes PHP has buffered
 * returns them only after the next packet or EOF, which holds back body bytes
 * that arrived with the headers. PHP only fixed this for TLS in 8.4.24 and
 * 8.5.9. On Windows such a read can also mark the transport as timed out
 * although it returned bytes. Capping each read at the buffered length serves
 * it from PHP's buffer alone.
 *
 * @internal
 */
final class TransportStream implements StreamInterface
{
    use NonSerializableTrait;
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function __clone()
    {
        // Detaching a clone must not detach this body's transport.
        $this->stream = clone $this->stream;
    }

    public function read(int $length): string
    {
        $buffered = $this->stream->getMetadata('unread_bytes');

        return $this->stream->read(\is_int($buffered) && $buffered > 0 ? \min($length, $buffered) : $length);
    }
}
