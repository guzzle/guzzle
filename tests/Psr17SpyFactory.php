<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A spy PSR-17 stream + response factory used by the handler tests to assert
 * that the built-in handlers create response body streams via the configured
 * stream factory and the response message via the configured response factory.
 *
 * Streams are returned as {@see SpyStream} and responses as {@see SpyResponse}
 * so tests can assert on the concrete type, and the call counters let tests
 * verify which code path created (or skipped creating) a stream.
 */
final class Psr17SpyFactory implements StreamFactoryInterface, ResponseFactoryInterface
{
    /** @var int */
    public $createStreamCalls = 0;

    /** @var int */
    public $createStreamFromResourceCalls = 0;

    /** @var int */
    public $createStreamFromFileCalls = 0;

    /** @var int */
    public $createResponseCalls = 0;

    public function createStream(string $content = ''): StreamInterface
    {
        ++$this->createStreamCalls;

        $resource = Psr7\Utils::tryFopen('php://temp', 'r+');
        if ($content !== '') {
            \fwrite($resource, $content);
            \rewind($resource);
        }

        return new SpyStream($resource);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        ++$this->createStreamFromFileCalls;

        return new SpyStream(Psr7\Utils::tryFopen($filename, $mode));
    }

    /**
     * @param resource $resource
     */
    public function createStreamFromResource($resource): StreamInterface
    {
        ++$this->createStreamFromResourceCalls;

        return new SpyStream($resource);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        ++$this->createResponseCalls;

        return new SpyResponse($code, [], null, '1.1', $reasonPhrase);
    }
}

final class SpyStream extends Stream
{
}

final class SpyResponse extends Response
{
}
