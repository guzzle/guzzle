<?php

namespace GuzzleHttp\Test\Handler\Network;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\StreamHandler
 */
class StreamHandlerTest extends TestCase
{
    public function setUp(): void
    {
        if (!($_SERVER['GUZZLE_TEST_ALLOW_NETWORK'] ?? false)) {
            self::markTestSkipped('This test requires the GUZZLE_TEST_ALLOW_NETWORK environment variable.');
        }
    }

    public function testSslRequestWorks()
    {
        $handler = new StreamHandler();

        $response = $handler(
            new Request('GET', 'https://www.example.com/'),
            [
                RequestOptions::STREAM => true,
            ]
        )->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Example Domain</h1>', (string) $response->getBody());
    }

    public function testSslRequestWorksWithForceIpResolve()
    {
        $handler = new StreamHandler();

        $response = $handler(
            new Request('GET', 'https://www.example.com/'),
            [
                RequestOptions::STREAM => true,
                'force_ip_resolve' => 'v4',
            ]
        )->wait();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<h1>Example Domain</h1>', (string) $response->getBody());
    }

    public function testSslRequestWorksWithForceIpResolveAfterRedirect()
    {
        $client = new Client(['handler' => HandlerStack::create(new StreamHandler())]);

        $response = $client->send(
            // Redirects to https://help.github.com/en/actions/reference/workflow-syntax-for-github-actions#jobsjob_idstepsrun.
            new Request('GET', 'https://git.io/JvXDl'),
            [
                RequestOptions::STREAM => true,
                'force_ip_resolve' => 'v4',
            ]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('jobsjob_idstepsrun', (string) $response->getBody());
    }
}
