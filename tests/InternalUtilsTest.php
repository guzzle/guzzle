<?php

namespace GuzzleHttp\Test;

use GuzzleHttp\Psr7;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

class InternalUtilsTest extends TestCase
{
    public function testCurrentTime()
    {
        self::assertGreaterThan(0, Utils::currentTime());
    }

    /**
     * @requires extension idn
     */
    public function testIdnConvert()
    {
        $uri = Psr7\Utils::uriFor('https://яндекс.рф/images');
        $uri = Utils::idnUriConvert($uri);
        self::assertSame('xn--d1acpjx3f.xn--p1ai', $uri->getHost());
    }
}
