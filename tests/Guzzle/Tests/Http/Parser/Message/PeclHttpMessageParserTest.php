<?php

namespace Guzzle\Tests\Http\Parser\Message;

use Guzzle\Http\Parser\Message\PeclHttpMessageParser;

class PeclHttpMessageParserTest extends MessageParserProvider
{
    protected function setUp()
    {
        if (!function_exists('http_parse_message')) {
            $this->markTestSkipped('pecl_http is not available.');
        }
    }

    /**
     * @covers Guzzle\Http\Parser\Message\PeclHttpMessageParser::parseRequest
     * @dataProvider requestProvider
     */
    public function testParsesRequests($message, $parts)
    {
        $parser = new PeclHttpMessageParser();
        $this->compareRequestResults($parts, $parser->parseRequest($message));
    }

    /**
     * @covers Guzzle\Http\Parser\Message\PeclHttpMessageParser::parseResponse
     * @dataProvider responseProvider
     */
    public function testParsesResponses($message, $parts)
    {
        $parser = new PeclHttpMessageParser();
        $this->compareResponseResults($parts, $parser->parseResponse($message));
    }
}
