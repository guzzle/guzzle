<?php

namespace GuzzleHttp\Tests\Encoder;

use GuzzleHttp\Encoder\JsonEncoder;
use GuzzleHttp\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class JsonEncoderTest extends TestCase
{
    public function testEncode()
    {
        $jsonEncoder = new JsonEncoder();
        $result = $jsonEncoder->encode(true);
        self::assertSame('true', $result);
    }

    public function testEncodeFailure()
    {
        $this->expectException(InvalidArgumentException::class);

        $invalidUtf8Sequence = "\xB1\x3233231";

        $jsonEncoder = new JsonEncoder();
        $jsonEncoder->encode($invalidUtf8Sequence);
    }

    public function testDecode()
    {
        $jsonDecoder = new JsonEncoder();
        $result = $jsonDecoder->decode('true');
        self::assertTrue($result);
    }

    public function testDecodeFailure()
    {
        $this->expectException(InvalidArgumentException::class);

        $jsonDecoder = new JsonEncoder();
        $jsonDecoder->decode('{{]]');
    }
}
