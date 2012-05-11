<?php

namespace Guzzle\Tests\Http\Parser\Message;

use Guzzle\Http\Parser\Message\PeclHttpMessageParser;

class PeclHttpMessageParserTest extends MessageParserProvider
{
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
