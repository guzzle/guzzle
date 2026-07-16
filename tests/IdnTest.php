<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

use GuzzleHttp\Idn;
use GuzzleHttp\Psr7;
use PHPUnit\Framework\TestCase;

class IdnTest extends TestCase
{
    /**
     * @requires extension idn
     */
    public function testConvertUri(): void
    {
        $uri = Psr7\Utils::uriFor('https://яндекс.рф/images');
        $uri = Idn::convertUri($uri);
        self::assertSame('xn--d1acpjx3f.xn--p1ai', $uri->getHost());
    }
}
