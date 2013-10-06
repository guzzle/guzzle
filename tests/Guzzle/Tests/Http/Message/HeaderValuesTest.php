<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Message\HeaderValues;

/**
 * @covers Guzzle\Http\Message\HeaderValues
 */
class HeaderValuesTest extends \PHPUnit_Framework_TestCase
{
    public function testCanSetInConstructor()
    {
        $v = new HeaderValues(['foo', 'bar']);
        $this->assertEquals(['foo', 'bar'], iterator_to_array($v));
    }

    public function testCanUseAsArray()
    {
        $v = new HeaderValues();
        $v[] = 'a';
        $v[] = 'b';
        $v[2] = 'c';
        $this->assertCount(3, $v);

        $this->assertEquals('a', $v[0]);
        $this->assertEquals('b', $v[1]);
        $this->assertEquals('c', $v[2]);
        $this->assertNull($v[10]);

        $this->assertTrue(isset($v[0]));
        $this->assertTrue(isset($v[1]));
        $this->assertTrue(isset($v[2]));
        $this->assertFalse(isset($v[3]));

        unset($v[0]);
        $this->assertFalse(isset($v[0]));
    }

    public function testCastToString()
    {
        $v = new HeaderValues(['a', 'b']);
        $this->assertEquals('a, b', $v);
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
            )
        );
    }

    /**
     * @dataProvider parseParamsProvider
     */
    public function testParseParams($header, $result)
    {
        $v = new HeaderValues([$header]);
        $this->assertEquals($result, $v->parseParams());
    }
}
