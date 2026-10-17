<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Decorates the transport stream of a buffered response so every read
 * observes the wall-clock deadline of the "timeout" request option.
 *
 * The transport resource must be in non-blocking mode: a blocking read waits
 * for data up to the transport's own timeout, so a deadline check between reads
 * never runs while the server stalls. Reads return whatever bytes are available
 * and poll briefly for more, so the deadline is checked between packets rather
 * than between buffers.
 *
 * @internal
 */
final class DeadlineSourceStream implements StreamInterface
{
    use NonSerializableTrait;
    use StreamDecoratorTrait;

    private const POLL_INTERVAL = 0.05;

    private StreamInterface $stream;

    private float $deadline;

    private ?float $readTimeout;

    /**
     * @param StreamInterface $stream      Transport stream in non-blocking mode.
     * @param float           $deadline    Deadline based on Clock::now().
     * @param float|null      $readTimeout Optional idle timeout per read, in seconds.
     */
    public function __construct(StreamInterface $stream, float $deadline, ?float $readTimeout)
    {
        $this->stream = $stream;
        $this->deadline = $deadline;
        $this->readTimeout = $readTimeout;
    }

    public function read(int $length): string
    {
        $idleStart = null;

        while (true) {
            $remaining = $this->deadline - Clock::now();
            if ($remaining <= 0) {
                throw new TimeoutException('Unable to read from stream: timed out');
            }

            $data = $this->stream->read($length);
            if ($data !== '') {
                return $data;
            }
            if ($this->stream->eof()) {
                return '';
            }

            $now = Clock::now();
            if ($idleStart === null) {
                $idleStart = $now;
            }
            if ($this->readTimeout !== null && $now - $idleStart >= $this->readTimeout) {
                throw new TimeoutException('Unable to read from stream: timed out');
            }

            $wait = \min($remaining, self::POLL_INTERVAL);
            if ($this->readTimeout !== null) {
                $wait = \min($wait, $this->readTimeout - ($now - $idleStart));
            }
            \usleep((int) \ceil($wait * 1e6));
        }
    }
}
