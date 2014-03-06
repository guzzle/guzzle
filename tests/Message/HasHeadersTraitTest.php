<?php

namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Message\HasHeadersInterface;
use GuzzleHttp\Message\HasHeadersTrait;

class HasThem implements HasHeadersInterface {
    use HasHeadersTrait;
}

class HasHeadersTraitTest extends \PHPUnit_Framework_TestCase
{
    public function testAddsHeadersWhenNotPresent()
    {
        $h = new HasThem();
        $h->addHeader('foo', 'bar');
        $this->assertInternalType('string', $h->getHeader('foo'));
        $this->assertEquals('bar', $h->getHeader('foo'));
    }

    public function testAddsHeadersWhenPresentSameCase()
    {
        $h = new HasThem();
        $h->addHeader('foo', 'bar')->addHeader('foo', 'baz');
        $this->assertEquals('bar, baz', $h->getHeader('foo'));
        $this->assertEquals(['bar', 'baz'], $h->getHeader('foo', true));
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
        $this->assertArrayHasKey('Foo', $result);
        $this->assertArrayNotHasKey('foo', $result);
        $this->assertArrayHasKey('boO', $result);
        $this->assertEquals(['bar', 'baz'], $result['Foo']);
        $this->assertEquals(['test'], $result['boO']);
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

    public function testSetHeaderOverwritesExistingValuesUsingHeaderArray()
    {
        $h = new HasThem();
        $h->setHeader('foo', ['bar']);
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
        $this->assertEquals(['foo' => ['a'], 'boo' => ['b']], $h->getHeaders());
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

    public function testReturnsCorrectTypeWhenMissing()
    {
        $h = new HasThem();
        $this->assertInternalType('string', $h->getHeader('foo'));
        $this->assertInternalType('array', $h->getHeader('foo', true));
    }

    public function testSetsIntegersAndFloatsAsHeaders()
    {
        $h = new HasThem();
        $h->setHeader('foo', 10);
        $h->setHeader('bar', 10.5);
        $h->addHeader('foo', 10);
        $h->addHeader('bar', 10.5);
        $this->assertSame('10, 10', $h->getHeader('foo'));
        $this->assertSame('10.5, 10.5', $h->getHeader('bar'));
    }
}
