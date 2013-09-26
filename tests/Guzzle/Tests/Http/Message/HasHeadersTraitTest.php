<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Message\HasHeadersInterface;
use Guzzle\Http\Message\HasHeadersTrait;
use Guzzle\Http\Message\HeaderValues;

class HasThem implements HasHeadersInterface {
    use HasHeadersTrait;
}

class testHasHeadersTraitTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testAddsHeadersWhenNotPresent()
    {
        $h = new HasThem();
        $h->addHeader('foo', 'bar');
        $this->assertInstanceOf('Guzzle\Http\Message\HeaderValuesInterface', $h->getHeader('foo'));
        $this->assertEquals('bar', $h->getHeader('foo'));
    }

    public function testAddsHeadersWhenPresentSameCase()
    {
        $h = new HasThem();
        $h->addHeader('foo', 'bar')->addHeader('foo', 'baz');
        $this->assertEquals(['bar', 'baz'], $h->getHeader('foo')->getIterator()->getArrayCopy());
        $this->assertEquals('bar, baz', $h->getHeader('foo'));
    }

    public function testAddsHeadersWhenPresentDifferentCase()
    {
        $h = new HasThem();
        $h->addHeader('Foo', 'bar')->addHeader('fOO', 'baz');
        $this->assertEquals('bar, baz', $h->getHeader('foo'));
    }

    public function testAddsHeadersWithArray()
    {
        $h = new HasThem();
        $h->addHeader('Foo', ['bar', 'baz']);
        $this->assertEquals('bar, baz', $h->getHeader('foo'));
    }

    public function testAddsHeadersWithHeaderValues()
    {
        $h = new HasThem();
        $h->addHeader('Foo', new HeaderValues(['bar', 'baz']));
        $this->assertEquals('bar, baz', $h->getHeader('foo'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testThrowsExceptionWhenInvalidValueProvidedToAddHeader()
    {
        (new HasThem())->addHeader('foo', false);
    }

    public function testGetHeadersReturnsAnArrayOfOverTheWireHeaderValues()
    {
        $h = new HasThem();
        $h->addHeader('foo', 'bar');
        $h->addHeader('Foo', 'baz');
        $h->addHeader('boO', 'test');
        $result = $h->getHeaders();
        $this->assertInternalType('array', $result);
        $this->assertArrayHasKey('foo', $result);
        $this->assertArrayHasKey('boO', $result);
        $this->assertEquals('bar, baz', (string) $result['foo']);
        $this->assertEquals('test', (string) $result['boO']);
    }

    public function testSetHeaderOverwritesExistingValues()
    {
        $h = new HasThem();
        $h->setHeader('foo', 'bar');
        $this->assertEquals('bar', $h->getHeader('foo'));
        $h->setHeader('Foo', 'baz');
        $this->assertEquals('baz', $h->getHeader('foo'));
        $this->assertArrayHasKey('Foo', $h->getHeaders());
    }

    public function testSetHeaderOverwritesExistingValuesUsingHeaderValuesObject()
    {
        $h = new HasThem();
        $h->setHeader('foo', new HeaderValues(['bar']));
        $this->assertEquals('bar', $h->getHeader('foo'));
    }

    public function testSetHeaderOverwritesExistingValuesUsingArray()
    {
        $h = new HasThem();
        $h->setHeader('foo', ['bar']);
        $this->assertEquals('bar', $h->getHeader('foo'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testThrowsExceptionWhenInvalidValueProvidedToSetHeader()
    {
        (new HasThem())->setHeader('foo', false);
    }

    public function testSetHeadersOverwritesAllHeaders()
    {
        $h = new HasThem();
        $h->setHeader('foo', 'bar');
        $h->setHeaders(['foo' => 'a', 'boo' => 'b']);
        $this->assertEquals(['foo' => 'a', 'boo' => 'b'], $h->getHeaders());
    }

    public function testChecksIfCaseInsensitiveHeaderIsPresent()
    {
        $h = new HasThem();
        $h->setHeader('foo', 'bar');
        $this->assertTrue($h->hasHeader('foo'));
        $this->assertTrue($h->hasHeader('Foo'));
        $h->setHeader('fOo', 'bar');
        $this->assertTrue($h->hasHeader('Foo'));
    }

    public function testRemovesHeaders()
    {
        $h = new HasThem();
        $h->setHeader('foo', 'bar');
        $h->removeHeader('foo');
        $this->assertFalse($h->hasHeader('foo'));
        $h->setHeader('Foo', 'bar');
        $h->removeHeader('FOO');
        $this->assertFalse($h->hasHeader('foo'));
    }
}
