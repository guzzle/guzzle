<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Decorates the transport stream of a streamed response so each read returns
 * the decoded bytes already available instead of waiting for the requested
 * length.
 *
 * A blocking read from a PHP stream that carries a read filter, such as the
 * dechunk filter, keeps reading from the transport until the filter has
 * produced the requested length, the transport reaches EOF, or the idle
 * timeout expires. Each read therefore waits for a single byte in blocking
 * mode, so the idle timeout still applies, and then takes whatever else is
 * available with the transport in non-blocking mode. stream_select() cannot
 * watch a stream that carries a filter, so it cannot replace the mode switch.
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

    private ?\Exception $failure = null;

    /**
     * @param StreamInterface $stream   Stream that reads from the transport.
     * @param resource        $resource Transport resource in blocking mode.
     */
    public function __construct(StreamInterface $stream, $resource)
    {
        $this->stream = $stream;
        $this->resource = $resource;
    }

    public function read(int $length): string
    {
        $this->throwFailure();

        if ($length <= 1 || !\is_resource($this->resource)) {
            return $this->stream->read($length);
        }

        $data = $this->stream->read(1);
        if ($data === '') {
            return '';
        }

        // The stream factory may have released the transport, or the
        // transport may not support non-blocking reads.
        if (!\is_resource($this->resource) || !\stream_set_blocking($this->resource, false)) {
            $this->resource = null;

            return $data;
        }

        try {
            return $data.$this->stream->read($length - 1);
        } catch (\Exception $e) {
            // Return the byte already taken from the transport first.
            $this->failure = $e;

            return $data;
        } finally {
            if (\is_resource($this->resource)) {
                \stream_set_blocking($this->resource, true);
            }
        }
    }

    public function getContents(): string
    {
        $this->throwFailure();

        return $this->stream->getContents();
    }

    private function throwFailure(): void
    {
        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;

            throw $failure;
        }
    }
}
