<?php

namespace Guzzle\Tests\Message;

use Guzzle\Common\Collection;
use Guzzle\Http\Message\Response;
use Guzzle\Stream\Stream;

/**
 * @covers Guzzle\Http\Message\Response
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
        $this->assertEquals("HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nfoo", (string) $response);
    }

    public function testParsesJsonResponses()
    {
        $response = new Response(200, [], Stream::factory('{"foo": "bar"}'));
        $this->assertEquals(array('foo' => 'bar'), $response->json());
        // Return array when null is a service response
        $response = new Response(200);
        $this->assertEquals([], $response->json());
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
     * @expectedException \RuntimeException
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

    /**
     * @expectedException \Guzzle\Common\Exception\RuntimeException
     */
    public function testPreventsComplexExternalEntities()
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE scan[<!ENTITY test SYSTEM "php://filter/read=convert.base64-encode/resource=ResponseTest.php">]><scan>&test;</scan>';
        $response = new Response(200, array(), $xml);

        $oldCwd = getcwd();
        chdir(__DIR__);
        try {
            $response->xml();
            chdir($oldCwd);
        } catch (\Exception $e) {
            chdir($oldCwd);
            throw $e;
        }
    }
}
