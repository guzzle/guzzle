<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\IncrementalStream;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\IncrementalStream
 */
class IncrementalStreamTest extends TestCase
{
    public function testReturnsTakenByteBeforeReadFailure(): void
    {
        $resource = Utils::tryFopen('php://temp', 'r+');
        $failure = new \RuntimeException('Unable to read from stream');
        $source = FnStream::decorate(Utils::streamFor($resource), [
            'read' => static function (int $length) use ($failure): string {
                if ($length === 1) {
                    return 'a';
                }

                throw $failure;
            },
        ]);
        $stream = new IncrementalStream($source, $resource);

        self::assertSame('a', $stream->read(8192));

        try {
            $stream->read(8192);
            self::fail('Expected the read failure');
        } catch (\RuntimeException $e) {
            self::assertSame($failure, $e);
        }
    }
}
