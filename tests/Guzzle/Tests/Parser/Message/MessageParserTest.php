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

    /**
     * @covers Guzzle\Parser\Message\MessageParser::parseRequest
     */
    public function testParsesRequestsWithMissingProtocol()
    {
        $parser = new MessageParser();
        $parts = $parser->parseRequest("GET /\r\nHost: Foo.com\r\n\r\n");
        $this->assertEquals('GET', $parts['method']);
        $this->assertEquals('HTTP', $parts['protocol']);
        $this->assertEquals('1.1', $parts['version']);
    }

    /**
     * @covers Guzzle\Parser\Message\MessageParser::parseRequest
     */
    public function testParsesRequestsWithMissingVersion()
    {
        $parser = new MessageParser();
        $parts = $parser->parseRequest("GET / HTTP\r\nHost: Foo.com\r\n\r\n");
        $this->assertEquals('GET', $parts['method']);
        $this->assertEquals('HTTP', $parts['protocol']);
        $this->assertEquals('1.1', $parts['version']);
    }

    /**
     * @covers Guzzle\Parser\Message\MessageParser::parseResponse
     */
    public function testParsesResponsesWithMissingReasonPhrase()
    {
        $parser = new MessageParser();
        $parts = $parser->parseResponse("HTTP/1.1 200\r\n\r\n");
        $this->assertEquals('200', $parts['code']);
        $this->assertEquals('', $parts['reason_phrase']);
        $this->assertEquals('HTTP', $parts['protocol']);
        $this->assertEquals('1.1', $parts['version']);
    }
}
