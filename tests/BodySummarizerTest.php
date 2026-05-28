<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\BodySummarizer;
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
}
