<?php

namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Message\Response;
use GuzzleHttp\Stream\Stream;

/**
 * @covers GuzzleHttp\Message\Response
 */
class ResponseTest extends \PHPUnit_Framework_TestCase
{
    public function testCanProvideCustomStatusCodeAndReasonPhrase()
    {
        $response = new Response(999, [], null, ['reason_phrase' => 'hi!']);
        $this->assertEquals(999, $response->getStatusCode());
        $this->assertEquals('hi!', $response->getReasonPhrase());
    }

    public function testConvertsToString()
    {
        $response = new Response(200);
        $this->assertEquals("HTTP/1.1 200 OK\r\n\r\n", (string) $response);
        // Add another header
        $response = new Response(200, ['X-Test' => 'Guzzle']);
        $this->assertEquals("HTTP/1.1 200 OK\r\nX-Test: Guzzle\r\n\r\n", (string) $response);
        $response = new Response(200, ['Content-Length' => 4], Stream::factory('test'));
        $this->assertEquals("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest", (string) $response);
    }

    public function testConvertsToStringAndSeeksToByteZero()
    {
        $response = new Response(200);
        $s = Stream::factory('foo');
        $s->read(1);
        $response->setBody($s);
        $this->assertEquals("HTTP/1.1 200 OK\r\n\r\nfoo", (string) $response);
    }

    public function testParsesJsonResponses()
    {
        $json = '{"foo": "bar"}';
        $response = new Response(200, [], Stream::factory($json));
        $this->assertEquals(['foo' => 'bar'], $response->json());
        $this->assertEquals(json_decode($json), $response->json(['object' => true]));

        $response = new Response(200);
        $this->assertEquals(null, $response->json());
    }

    /**
     * @expectedException \RuntimeException
     * @expectedExceptionMessage Unable to parse response body into JSON: 4
     */
    public function testThrowsExceptionWhenFailsToParseJsonResponse()
    {
        $response = new Response(200, [], Stream::factory('{"foo": "'));
        $response->json();
    }

    public function testParsesXmlResponses()
    {
        $response = new Response(200, [], Stream::factory('<abc><foo>bar</foo></abc>'));
        $this->assertEquals('bar', (string) $response->xml()->foo);
        // Always return a SimpleXMLElement from the xml method
        $response = new Response(200);
        $this->assertEmpty((string) $response->xml()->foo);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ParseException
     * @expectedExceptionMessage Unable to parse response body into XML: String could not be parsed as XML
     */
    public function testThrowsExceptionWhenFailsToParseXmlResponse()
    {
        $response = new Response(200, [], Stream::factory('<abc'));
        $response->xml();
    }

    public function testHasEffectiveUrl()
    {
        $r = new Response(200);
        $this->assertNull($r->getEffectiveUrl());
        $r->setEffectiveUrl('http://www.test.com');
        $this->assertEquals('http://www.test.com', $r->getEffectiveUrl());
    }

    public function testPreventsComplexExternalEntities()
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE scan[<!ENTITY test SYSTEM "php://filter/read=convert.base64-encode/resource=ResponseTest.php">]><scan>&test;</scan>';
        $response = new Response(200, array(), Stream::factory($xml));

        $oldCwd = getcwd();
        chdir(__DIR__);
        try {
            $xml = $response->xml();
            chdir($oldCwd);
            $this->markTestIncomplete('Did not throw the expected exception! XML resolved as: ' . $xml->asXML());
        } catch (\Exception $e) {
            chdir($oldCwd);
        }
    }
}
