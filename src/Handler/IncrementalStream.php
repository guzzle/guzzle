<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Returns available decoded bytes without filling a filtered stream's buffer.
 *
 * @internal
 */
final class IncrementalStream implements StreamInterface
{
    use NonSerializableTrait;
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    /** @var resource|null */
    private $resource;

    /**
     * @param resource $resource Blocking transport wrapped by the stream.
     */
    public function __construct(StreamInterface $stream, $resource)
    {
        $this->stream = $stream;
        $this->resource = $resource;
    }

    public function read(int $length): string
    {
        if ($length <= 1 || $this->resource === null) {
            return $this->stream->read($length);
        }

        // Keep the native idle timeout while waiting for data. Larger blocking
        // reads on PHP's filtered streams wait until the buffer fills or EOF.
        $first = $this->stream->read(1);
        if ($first === '') {
            return '';
        }

        \stream_set_blocking($this->resource, false);
        try {
            return $first.$this->stream->read($length - 1);
        } catch (TimeoutException $e) {
            // Non-blocking reads cannot time out, but PHP's TLS streams retain
            // an earlier timeout flag. Hard errors still propagate separately.
            return $first;
        } finally {
            \stream_set_blocking($this->resource, true);
        }
    }

    public function close(): void
    {
        $this->resource = null;
        $this->stream->close();
    }

    public function detach()
    {
        $this->resource = null;

        return $this->stream->detach();
    }
}
