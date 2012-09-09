<?php

namespace Guzzle\Tests\Plugin\Cache;

use Guzzle\Http\Message\Request;
use Guzzle\Plugin\Cache\DefaultCacheKeyProvider;

/**
 * @covers Guzzle\Plugin\Cache\DefaultCacheKeyProvider
 */
class DefaultCacheKeyProviderTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testGeneratesCacheKey()
    {
        $request = new Request('GET', 'http://foo.com?a=b&c=d');
        $provider = new DefaultCacheKeyProvider();
        $this->assertNotEmpty($provider->getCacheKey($request));
        $this->assertNotEmpty($request->getParams()->get(DefaultCacheKeyProvider::CACHE_KEY));
        $this->assertEquals((string) $request, $request->getParams()->get(DefaultCacheKeyProvider::CACHE_KEY_RAW));
    }

    public function testFiltersCacheKey()
    {
        $request = new Request('GET', 'http://foo.com?a=b&c=d', array(
            'Abc' => '123',
            'Def' => '456'
        ));
        $request->getParams()->set(DefaultCacheKeyProvider::CACHE_KEY_FILTER, 'header=Def; query=c');
        $provider = new DefaultCacheKeyProvider();
        $provider->getCacheKey($request);
        $this->assertNotEmpty($request->getParams()->get(DefaultCacheKeyProvider::CACHE_KEY));
        $cloned = clone $request;
        $cloned->getQuery()->remove('c');
        $cloned->removeHeader('Def');
        $this->assertEquals((string) $cloned, $request->getParams()->get(DefaultCacheKeyProvider::CACHE_KEY_RAW));
    }
}
