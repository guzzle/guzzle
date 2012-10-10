<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Message\Header;

/**
 * @covers Guzzle\Http\Message\Header
 */
class HeaderTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected $test = array(
        'zoo'   => array('foo', 'Foo'),
        'Zoo'   => 'bar',
    );

    public function testRawReturnsRawArray()
    {
        $i = new Header('Zoo', $this->test);

        $this->assertEquals(array(
            'zoo' => array('foo', 'Foo'),
            'Zoo' => array('bar')
        ), $i->raw());
    }

    public function testStoresHeaderName()
    {
        $i = new Header('Zoo', $this->test);
        $this->assertEquals('Zoo', $i->getName());
    }

    public function testConvertsToString()
    {
        $i = new Header('Zoo', $this->test);
        $this->assertEquals('foo, Foo, bar', (string) $i);
        $i->setGlue('; ');
        $this->assertEquals('foo; Foo; bar', (string) $i);
    }

    public function testNormalizesCases()
    {
        $h = new Header('Zoo', $this->test);
        $h->normalize();
        $this->assertEquals(array(
            'Zoo' => array('foo', 'Foo', 'bar')
        ), $h->raw());
    }

    public function testCanSearchForValues()
    {
        $h = new Header('Zoo', $this->test);
        $this->assertTrue($h->hasValue('foo'));
        $this->assertTrue($h->hasValue('Foo'));
        $this->assertTrue($h->hasValue('bar'));
        $this->assertFalse($h->hasValue('moo'));

        $this->assertFalse($h->hasValue('FoO'));
        $this->assertTrue($h->hasValue('FoO', true));
    }

    public function testIsCountable()
    {
        $h = new Header('Zoo', $this->test);
        $this->assertEquals(3, count($h));
    }

    public function testCanBeIterated()
    {
        $h = new Header('Zoo', $this->test);
        $results = array();
        foreach ($h as $key => $value) {
            $results[$key] = $value;
        }
        $this->assertEquals(array(
            'foo', 'Foo', 'bar'
        ), $results);
    }

    public function testAllowsFalseyValues()
    {
        // Allows 0
        $h = new Header('Foo', 0, ';');
        $this->assertEquals('0', (string) $h);
        $this->assertEquals(1, count($h));
        $this->assertEquals(';', $h->getGlue());

        // Does not add a null header by default
        $h = new Header('Foo');
        $this->assertEquals('', (string) $h);
        $this->assertEquals(0, count($h));

        // Allows null array for a single null header
        $h = new Header('Foo', array(null));
        $this->assertEquals('', (string) $h);
        $this->assertEquals(1, count($h));

        // Allows empty string
        $h = new Header('Foo', '');
        $this->assertEquals('', (string) $h);
        $this->assertEquals(1, count($h));
    }

    public function testUsesHeaderNameWhenNoneIsSupplied()
    {
        $h = new Header('Foo', 'bar', ';');
        $h->add('baz');
        $this->assertEquals(array('Foo'), array_keys($h->raw()));
    }

    public function testCanCheckForExactHeaderValues()
    {
        $h = new Header('Foo', 'bar', ';');
        $this->assertTrue($h->hasExactHeader('Foo'));
        $this->assertFalse($h->hasExactHeader('foo'));
    }

    public function testCanRemoveValues()
    {
        $h = new Header('Foo', array('Foo', 'baz', 'bar'));
        $h->removeValue('bar');
        $this->assertTrue($h->hasValue('Foo'));
        $this->assertFalse($h->hasValue('bar'));
        $this->assertTrue($h->hasValue('baz'));
    }
}
