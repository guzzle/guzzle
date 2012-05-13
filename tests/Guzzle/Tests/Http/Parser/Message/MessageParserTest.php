<?php

namespace Guzzle\Tests\Http\Parser\Message;

use Guzzle\Http\Parser\Message\MessageParser;

class MessageParserTest extends MessageParserProvider
{
    /**
     * @covers Guzzle\Http\Parser\Message\AbstractMessageParser::getUrlPartsFromMessage
     * @covers Guzzle\Http\Parser\Message\MessageParser::parseMessage
     * @covers Guzzle\Http\Parser\Message\MessageParser::parseRequest
     * @dataProvider requestProvider
     */
    public function testParsesRequests($message, $parts)
    {
        $parser = new MessageParser();
        $this->compareRequestResults($parts, $parser->parseRequest($message));
    }

    /**
     * @covers Guzzle\Http\Parser\Message\MessageParser::parseMessage
     * @covers Guzzle\Http\Parser\Message\MessageParser::parseResponse
     * @dataProvider responseProvider
     */
    public function testParsesResponses($message, $parts)
    {
        $parser = new MessageParser();
        $this->compareResponseResults($parts, $parser->parseResponse($message));
    }
}
