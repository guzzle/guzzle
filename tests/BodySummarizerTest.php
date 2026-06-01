<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\BodySummarizer;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class BodySummarizerTest extends TestCase
{
    public function testSummarizeReadsFromStartAndRestoresBodyCursor(): void
    {
        $body = Utils::streamFor('abcdef');
        $body->seek(3);
        $response = new Response(200, [], $body);

        self::assertSame('abcdef', (new BodySummarizer())->summarize($response));
        self::assertSame(3, $body->tell());
    }

    public function testSummarizeWithCustomTruncationRestoresBodyCursor(): void
    {
        $body = Utils::streamFor('abcdef');
        $body->seek(3);
        $response = new Response(200, [], $body);

        self::assertSame('abc (truncated...)', (new BodySummarizer(3))->summarize($response));
        self::assertSame(3, $body->tell());
    }

    public function testSummarizeReturnsNullWhenBodySummaryFails(): void
    {
        $body = FnStream::decorate(Utils::streamFor('abcdef'), [
            'tell' => static function (): int {
                throw new \Exception('tell failed');
            },
        ]);
        $response = new Response(200, [], $body);

        self::assertNull((new BodySummarizer())->summarize($response));
    }

    public function testSummarizePropagatesBodySummaryError(): void
    {
        $previous = new \Error('tell bug');
        $body = FnStream::decorate(Utils::streamFor('abcdef'), [
            'tell' => static function () use ($previous): int {
                throw $previous;
            },
        ]);
        $response = new Response(200, [], $body);

        try {
            (new BodySummarizer())->summarize($response);
            self::fail('Expected Error');
        } catch (\Error $e) {
            self::assertSame($previous, $e);
        }
    }
}
