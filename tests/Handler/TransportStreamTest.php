<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\TransportStream;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\TransportStream
 */
class TransportStreamTest extends TestCase
{
    public function testReadsNoMoreThanPhpHasBuffered(): void
    {
        $lengths = [];
        $unread = 3;
        $stream = new TransportStream(FnStream::decorate(Utils::streamFor(''), [
            'read' => static function (int $length) use (&$lengths): string {
                $lengths[] = $length;

                return '';
            },
            'getMetadata' => static function (?string $key = null) use (&$unread) {
                return $key === 'unread_bytes' ? $unread : null;
            },
        ]));

        $stream->read(8192);
        $unread = 0;
        $stream->read(8192);

        self::assertSame([3, 8192], $lengths);
    }
}
