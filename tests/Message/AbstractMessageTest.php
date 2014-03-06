<?php

namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Message\AbstractMessage;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Stream\Stream;

/**
 * @covers \GuzzleHttp\Message\AbstractMessage
 */
class AbstractMessageTest extends \PHPUnit_Framework_TestCase
{
    public function testHasProtocolVersion()
    {
        $m = new Message();
        $this->assertEquals(1.1, $m->getProtocolVersion());
    }

    public function testHasHeaders()
    {
        $m = new Message();
        $this->assertFalse($m->hasHeader('foo'));
        $m->addHeader('foo', 'bar');
        $this->assertTrue($m->hasHeader('foo'));
    }

    public function testInitializesMessageWithProtocolVersionOption()
    {
        $m = new Request('GET', '/', [], null, [
            'protocol_version' => '10'
        ]);
        $this->assertEquals(10, $m->getProtocolVersion());
    }

    public function testHasBody()
    {
        $m = new Message();
        $this->assertNull($m->getBody());
        $s = Stream::factory('test');
        $m->setBody($s);
        $this->assertSame($s, $m->getBody());
        $this->assertFalse($m->hasHeader('Content-Length'));
    }

    public function testCanRemoveBodyBySettingToNullAndRemovesCommonBodyHeaders()
    {
        $m = new Message();
        $m->setBody(Stream::factory('foo'));
        $m->setHeader('Content-Length', 3)->setHeader('Transfer-Encoding', 'chunked');
        $m->setBody(null);
        $this->assertNull($m->getBody());
        $this->assertFalse($m->hasHeader('Content-Length'));
        $this->assertFalse($m->hasHeader('Transfer-Encoding'));
    }

    public function testCastsToString()
    {
        $m = new Message();
        $m->setHeader('foo', 'bar');
        $m->setBody(Stream::factory('baz'));
        $this->assertEquals("Foo!\r\nfoo: bar\r\n\r\nbaz", (string) $m);
    }

    public function parseParamsProvider()
    {
        $res1 = array(
            array(
                '<http:/.../front.jpeg>',
                'rel' => 'front',
                'type' => 'image/jpeg',
            ),
            array(
                '<http://.../back.jpeg>',
                'rel' => 'back',
                'type' => 'image/jpeg',
            ),
        );

        return array(
            array(
                '<http:/.../front.jpeg>; rel="front"; type="image/jpeg", <http://.../back.jpeg>; rel=back; type="image/jpeg"',
                $res1
            ),
            array(
                '<http:/.../front.jpeg>; rel="front"; type="image/jpeg",<http://.../back.jpeg>; rel=back; type="image/jpeg"',
                $res1
            ),
            array(
                'foo="baz"; bar=123, boo, test="123", foobar="foo;bar"',
                array(
                    array('foo' => 'baz', 'bar' => '123'),
                    array('boo'),
                    array('test' => '123'),
                    array('foobar' => 'foo;bar')
                )
            ),
            array(
                '<http://.../side.jpeg?test=1>; rel="side"; type="image/jpeg",<http://.../side.jpeg?test=2>; rel=side; type="image/jpeg"',
                array(
                    array('<http://.../side.jpeg?test=1>', 'rel' => 'side', 'type' => 'image/jpeg'),
                    array('<http://.../side.jpeg?test=2>', 'rel' => 'side', 'type' => 'image/jpeg')
                )
            ),
            array(
                '',
                array()
            )
        );
    }

    /**
     * @dataProvider parseParamsProvider
     */
    public function testParseParams($header, $result)
    {
        $request = new Request('GET', '/', ['foo' => $header]);
        $this->assertEquals($result, Message::parseHeader($request, 'foo'));
    }
}

class Message extends AbstractMessage
{
    protected function getStartLine()
    {
        return 'Foo!';
    }
}
