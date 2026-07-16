<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\Clock;
use PHPUnit\Framework\TestCase;

class ClockTest extends TestCase
{
    public function testNow(): void
    {
        self::assertGreaterThan(0, Clock::now());
    }
}
