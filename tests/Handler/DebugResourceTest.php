<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\DebugResource;
use PHPUnit\Framework\TestCase;

class DebugResourceTest extends TestCase
{
    public function testReturnsDebugResource(): void
    {
        self::assertIsResource(DebugResource::from());
    }
}
