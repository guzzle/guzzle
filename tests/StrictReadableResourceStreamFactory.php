<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Psr7;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class StrictReadableResourceStreamFactory implements StreamFactoryInterface
{
    /** @var int */
    public $createStreamFromResourceCalls = 0;

    public function createStream(string $content = ''): StreamInterface
    {
        $resource = Psr7\Utils::tryFopen('php://temp', 'r+');
        if ($content !== '') {
            \fwrite($resource, $content);
            \rewind($resource);
        }

        return new Psr7\Stream($resource);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return new Psr7\Stream(Psr7\Utils::tryFopen($filename, $mode));
    }

    /**
     * @param resource $resource
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        ++$this->createStreamFromResourceCalls;

        $mode = \stream_get_meta_data($resource)['mode'] ?? '';
        if (\strpos($mode, 'r') === false && \strpos($mode, '+') === false) {
            throw new \RuntimeException('resource is not readable');
        }

        return new Psr7\Stream($resource);
    }
}
