<?php

namespace Guzzle\Tests\Http\Parser;

use Guzzle\Http\Parser\ParserRegistry;

/**
 * Guzzle\Http\Parser\ParserRegistry
 */
class ParserRegistryTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testStoresObjects()
    {
        $c = new \stdClass();
        ParserRegistry::set('foo', $c);
        $this->assertSame($c, ParserRegistry::get('foo'));
    }

    public function testReturnsNullWhenNotFound()
    {
        $this->assertNull(ParserRegistry::get('FOO'));
    }

    public function testReturnsLazyLoadedDefault()
    {
        // Clear out what might be cached
        $refObject = new \ReflectionClass('Guzzle\Http\Parser\ParserRegistry');
        $refProperty = $refObject->getProperty('instances');
        $refProperty->setAccessible(true);
        $refProperty->setValue(null, array());

        $c = ParserRegistry::get('cookie');
        $this->assertInstanceOf('Guzzle\Http\Parser\Cookie\CookieParser', $c);
        $this->assertSame($c, ParserRegistry::get('cookie'));
    }
}
