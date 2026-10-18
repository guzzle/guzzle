<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\Handler\Protocols;
use PHPUnit\Framework\TestCase;

class ProtocolsTest extends TestCase
{
    public function testNormalizeProtocolsAcceptsLowercaseProtocols(): void
    {
        self::assertSame(['http', 'https'], Protocols::normalize(['http', 'https', 'http']));
    }

    public function testNormalizeProtocolsRejectsUppercaseProtocols(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('protocols may only contain "http" and "https"');

        Protocols::normalize(['HTTPS']);
    }
}
