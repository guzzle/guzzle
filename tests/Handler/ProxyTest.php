<?php

declare(strict_types=1);

namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @covers \GuzzleHttp\Handler\Proxy
 */
class ProxyTest extends TestCase
{
    public function testSendsToNonSync(): void
    {
        $a = $b = null;
        $m1 = new MockHandler([static function (RequestInterface $v, array $options) use (&$a): void {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function (RequestInterface $v, array $options) use (&$b): void {
            $b = $v;
        }]);
        $h = Proxy::wrapSync($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), []);
        self::assertNotNull($a);
        self::assertNull($b);
    }

    public function testSendsToSync(): void
    {
        $a = $b = null;
        $m1 = new MockHandler([static function (RequestInterface $v, array $options) use (&$a): void {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function (RequestInterface $v, array $options) use (&$b): void {
            $b = $v;
        }]);
        $h = Proxy::wrapSync($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), [RequestOptions::SYNCHRONOUS => true]);
        self::assertNull($a);
        self::assertNotNull($b);
    }

    public function testSendsToStreaming(): void
    {
        $a = $b = null;
        $m1 = new MockHandler([static function (RequestInterface $v, array $options) use (&$a): void {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function (RequestInterface $v, array $options) use (&$b): void {
            $b = $v;
        }]);
        $h = Proxy::wrapStreaming($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), []);
        self::assertNotNull($a);
        self::assertNull($b);
    }

    public function testSendsToNonStreaming(): void
    {
        $a = $b = null;
        $m1 = new MockHandler([static function (RequestInterface $v, array $options) use (&$a): void {
            $a = $v;
        }]);
        $m2 = new MockHandler([static function (RequestInterface $v, array $options) use (&$b): void {
            $b = $v;
        }]);
        $h = Proxy::wrapStreaming($m1, $m2);
        $h(new Request('GET', 'http://foo.com'), ['stream' => true]);
        self::assertNull($a);
        self::assertNotNull($b);
    }
}
