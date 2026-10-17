<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

/**
 * Experiment only: minimal userland chunked transfer decoder over an unfiltered transport.
 *
 * @internal
 */
final class ExpChunkedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;
    private string $in = '';
    private int $remaining = 0;   // bytes left in the current chunk
    private int $state = 0;       // 0 size line, 1 data, 2 CRLF after data, 3 trailers, 4 done
    private int $position = 0;

    public function __construct(StreamInterface $stream)
    {
        $this->stream = $stream;
    }

    public function eof(): bool
    {
        return $this->state === 4;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function read(int $length): string
    {
        while (true) {
            if ($this->state === 4 || $length === 0) {
                return '';
            }
            if ($this->state === 1 && $this->in !== '') {
                $n = \min($length, $this->remaining, \strlen($this->in));
                $out = \substr($this->in, 0, $n);
                $this->in = (string) \substr($this->in, $n);
                $this->remaining -= $n;
                if ($this->remaining === 0) {
                    $this->state = 2;
                }
                $this->position += $n;

                return $out;
            }
            if ($this->state === 0 || $this->state === 2 || $this->state === 3) {
                $eol = \strpos($this->in, "\r\n");
                if ($eol !== false) {
                    $line = \substr($this->in, 0, $eol);
                    $this->in = (string) \substr($this->in, $eol + 2);
                    if ($this->state === 2) {
                        if ($line !== '') {
                            throw new \RuntimeException('Malformed chunk');
                        }
                        $this->state = 0;
                    } elseif ($this->state === 0) {
                        $size = \explode(';', $line, 2)[0];
                        if (1 !== \preg_match('/^[0-9a-fA-F]{1,15}$/D', $size)) {
                            throw new \RuntimeException('Malformed chunk size');
                        }
                        $this->remaining = (int) \hexdec($size);
                        $this->state = $this->remaining === 0 ? 3 : 1;
                    } elseif ($line === '') {
                        $this->state = 4;
                    }
                    continue;
                }
            }
            $data = $this->stream->read(8192);
            if ($data === '') {
                if ($this->stream->eof()) {
                    throw new \RuntimeException('Chunked body ended prematurely');
                }

                return '';
            }
            $this->in .= $data;
        }
    }
}
