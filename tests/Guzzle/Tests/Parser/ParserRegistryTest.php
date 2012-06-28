<?php

namespace Guzzle\Tests\Parser;

use Guzzle\Parser\ParserRegistry;

/**
 * Guzzle\Parser\ParserRegistry
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
        $refObject = new \ReflectionClass('Guzzle\Parser\ParserRegistry');
        $refProperty = $refObject->getProperty('instances');
        $refProperty->setAccessible(true);
        $refProperty->setValue(null, array());

        $c = ParserRegistry::get('cookie');
        $this->assertInstanceOf('Guzzle\Parser\Cookie\CookieParser', $c);
        $this->assertSame($c, ParserRegistry::get('cookie'));
    }
}
