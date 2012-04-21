<?php

namespace Guzzle\Tests\Service;

use Guzzle\Tests\Service\Mock\MockInflector as Inflector;

/**
 * @covers Guzzle\Service\Inflector
 */
class InflectorTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testSnake()
    {
        $this->assertEquals('camel_case', Inflector::snake('camelCase'));
        $this->assertEquals('camel_case', Inflector::snake('CamelCase'));
        $this->assertEquals('camel_case_words', Inflector::snake('CamelCaseWords'));
        $this->assertEquals('camel_case_words', Inflector::snake('CamelCase_words'));
        $this->assertEquals('test', Inflector::snake('test'));
        $this->assertEquals('test', Inflector::snake('test'));
        $this->assertEquals('expect100_continue', Inflector::snake('Expect100Continue'));
    }

    public function testCamel()
    {
        $this->assertEquals('CamelCase', Inflector::camel('camel_case'));
        $this->assertEquals('CamelCaseWords', Inflector::camel('camel_case_words'));
        $this->assertEquals('Test', Inflector::camel('test'));
        $this->assertEquals('Expect100Continue', ucfirst(Inflector::camel('expect100_continue')));

        // Get from cache
        $this->assertEquals('Test', Inflector::camel('test', false));
    }

    public function testProtectsAgainstCacheOverflow()
    {
        $perCachePurge = Inflector::MAX_ENTRIES_PER_CACHE * 0.1;

        $cached = Inflector::getCache();
        $currentSnake = count($cached['snake']);
        $currentCamel = count($cached['camel']);
        unset($cached);

        // Fill each cache with garbage, then make sure it flushes out cached
        // entries and maintains a cache cap
        while (++$currentSnake < Inflector::MAX_ENTRIES_PER_CACHE + 1) {
            Inflector::snake(uniqid());
        }
        while (++$currentCamel < Inflector::MAX_ENTRIES_PER_CACHE + 1) {
            Inflector::camel(uniqid());
        }

        $cached = Inflector::getCache();
        $this->assertEquals(Inflector::MAX_ENTRIES_PER_CACHE, count($cached['snake']));
        $this->assertEquals(Inflector::MAX_ENTRIES_PER_CACHE, count($cached['camel']));
        unset($cached);

        // Add another element to each cache to remove 10% of the cache
        Inflector::snake(uniqid());
        Inflector::camel(uniqid());

        $cached = Inflector::getCache();
        $this->assertEquals(Inflector::MAX_ENTRIES_PER_CACHE - $perCachePurge + 1, count($cached['snake']));
        $this->assertEquals(Inflector::MAX_ENTRIES_PER_CACHE - $perCachePurge + 1, count($cached['camel']));
    }
}
