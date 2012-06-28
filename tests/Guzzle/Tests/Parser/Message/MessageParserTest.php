<?php

namespace Guzzle\Tests\Parser\Message;

use Guzzle\Parser\Message\MessageParser;

class MessageParserTest extends MessageParserProvider
{
    /**
     * @covers Guzzle\Parser\Message\AbstractMessageParser::getUrlPartsFromMessage
     * @covers Guzzle\Parser\Message\MessageParser::parseMessage
     * @covers Guzzle\Parser\Message\MessageParser::parseRequest
     * @dataProvider requestProvider
     */
    public function testParsesRequests($message, $parts)
    {
        $parser = new MessageParser();
        $this->compareRequestResults($parts, $parser->parseRequest($message));
    }

    /**
     * @covers Guzzle\Parser\Message\MessageParser::parseMessage
     * @covers Guzzle\Parser\Message\MessageParser::parseResponse
     * @dataProvider responseProvider
     */
    public function testParsesResponses($message, $parts)
    {
        $parser = new MessageParser();
        $this->compareResponseResults($parts, $parser->parseResponse($message));
    }
}
