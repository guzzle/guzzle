<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\BodySummarizer;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\BodySummarizer
 */
class BodySummarizerTest extends TestCase
{
    public function testSummarizeReturnsBodyWhenUnderDefaultLimit(): void
    {
        $summarizer = new BodySummarizer();
        $response = new Response(200, [], 'Hello World');
        self::assertSame('Hello World', $summarizer->summarize($response));
    }

    public function testSummarizeReturnsNullForEmptyBody(): void
    {
        $summarizer = new BodySummarizer();
        $response = new Response(200, [], '');
        self::assertNull($summarizer->summarize($response));
    }

    public function testSummarizeWithNullTruncateAtUsesDefaultLimit(): void
    {
        $summarizer = new BodySummarizer(null);
        $content = \str_repeat('x', 121);
        $response = new Response(200, [], $content);
        self::assertSame(\str_repeat('x', 120).' (truncated...)', $summarizer->summarize($response));
    }

    public function testSummarizeTruncatesBodyAtCustomLimit(): void
    {
        $summarizer = new BodySummarizer(5);
        $response = new Response(200, [], 'Hello World');
        self::assertSame('Hello (truncated...)', $summarizer->summarize($response));
    }

    public function testSummarizeDoesNotTruncateBodyAtExactLimit(): void
    {
        $summarizer = new BodySummarizer(5);
        $response = new Response(200, [], 'Hello');
        self::assertSame('Hello', $summarizer->summarize($response));
    }

    public function testSummarizeWorksWithRequest(): void
    {
        $summarizer = new BodySummarizer();
        $request = new Request('POST', '/', [], 'request body');
        self::assertSame('request body', $summarizer->summarize($request));
    }
}
