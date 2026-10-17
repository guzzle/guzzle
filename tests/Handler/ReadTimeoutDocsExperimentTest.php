<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use PHPUnit\Framework\TestCase;

/**
 * Temporary CI check of the documented read_timeout behaviour. Not for merge.
 */
class ReadTimeoutDocsExperimentTest extends TestCase
{
    public function testReadThrowsOnTimeout()
    {
        Server::flush();
        $body = (new StreamHandler())(
            new Request('GET', Server::$url.'guzzle-server/read-timeout'),
            [RequestOptions::READ_TIMEOUT => 0.5, RequestOptions::STREAM => true]
        )->wait()->getBody();

        self::assertSame("sleeping 60 seconds ...\n", $body->read(1024));

        try {
            $result = $body->read(1024);
            \fwrite(\STDERR, "\n[docs-diag] PHP ".\PHP_VERSION.' read returned '.\var_export($result, true)."\n");
            self::fail('read() did not throw');
        } catch (\RuntimeException $e) {
            \fwrite(\STDERR, "\n[docs-diag] PHP ".\PHP_VERSION.' read threw '.\get_class($e).': '.$e->getMessage()."\n");
        }

        self::assertFalse($body->eof());
    }

    public function testGetContentsReturnsBytesBeforeTimeout()
    {
        Server::flush();
        $body = (new StreamHandler())(
            new Request('GET', Server::$url.'guzzle-server/read-timeout'),
            [RequestOptions::READ_TIMEOUT => 0.5, RequestOptions::STREAM => true]
        )->wait()->getBody();

        $contents = $body->getContents();
        \fwrite(\STDERR, "\n[docs-diag] PHP ".\PHP_VERSION.' getContents returned '.\var_export($contents, true).' eof '.\var_export($body->eof(), true)."\n");
        self::assertSame("sleeping 60 seconds ...\n", $contents);
        self::assertFalse($body->eof());
    }
}
