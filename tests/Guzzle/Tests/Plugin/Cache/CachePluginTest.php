<?php

namespace Guzzle\Tests\Plugin\Cache;

use Guzzle\Common\Event;
use Guzzle\Common\Version;
use Guzzle\Cache\DoctrineCacheAdapter;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Plugin\Cache\CachePlugin;
use Guzzle\Plugin\Cache\DefaultCacheStorage;
use Guzzle\Plugin\Cache\CallbackCanCacheStrategy;
use Doctrine\Common\Cache\ArrayCache;

/**
 * @group server
 * @covers Guzzle\Plugin\Cache\CachePlugin
 */
class CachePluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testAddsDefaultStorage()
    {
        $plugin = new CachePlugin();
        $this->assertInstanceOf('Guzzle\Plugin\Cache\CacheStorageInterface', $this->readAttribute($plugin, 'storage'));
    }

    public function testAddsDefaultCollaborators()
    {
        $this->assertNotEmpty(CachePlugin::getSubscribedEvents());
        $plugin = new CachePlugin(array(
            'storage' => $this->getMockBuilder('Guzzle\Plugin\Cache\CacheStorageInterface')->getMockForAbstractClass()
        ));
        $this->assertInstanceOf('Guzzle\Plugin\Cache\CacheStorageInterface', $this->readAttribute($plugin, 'storage'));
        $this->assertInstanceOf(
            'Guzzle\Plugin\Cache\CacheKeyProviderInterface',
            $this->readAttribute($plugin, 'keyProvider')
        );
        $this->assertInstanceOf(
            'Guzzle\Plugin\Cache\CanCacheStrategyInterface',
            $this->readAttribute($plugin, 'canCache')
        );
        $this->assertInstanceOf(
            'Guzzle\Plugin\Cache\RevalidationInterface',
            $this->readAttribute($plugin, 'revalidation')
        );
    }

    public function testAddsCallbackCollaborators()
    {
        $this->assertNotEmpty(CachePlugin::getSubscribedEvents());
        $plugin = new CachePlugin(array(
            'storage' => $this->getMockBuilder('Guzzle\Plugin\Cache\CacheStorageInterface')->getMockForAbstractClass(),
            'can_cache'    => function () {},
            'key_provider' => function () {}
        ));
        $this->assertInstanceOf(
            'Guzzle\Plugin\Cache\CallbackCacheKeyProvider',
            $this->readAttribute($plugin, 'keyProvider')
        );
        $this->assertInstanceOf(
            'Guzzle\Plugin\Cache\CallbackCanCacheStrategy',
            $this->readAttribute($plugin, 'canCache')
        );
    }

    public function testCanPassCacheAsOnlyArgumentToConstructor()
    {
        $p = new CachePlugin(new DoctrineCacheAdapter(new ArrayCache()));
        $p = new CachePlugin(new DefaultCacheStorage(new DoctrineCacheAdapter(new ArrayCache())));
    }

    public function testUsesCreatedCacheStorage()
    {
        $plugin = new CachePlugin(array(
            'adapter' => $this->getMockBuilder('Guzzle\Cache\CacheAdapterInterface')->getMockForAbstractClass()
        ));
        $this->assertInstanceOf('Guzzle\Plugin\Cache\CacheStorageInterface', $this->readAttribute($plugin, 'storage'));
    }

    public function testUsesProvidedOptions()
    {
        $can = $this->getMockBuilder('Guzzle\Plugin\Cache\CanCacheStrategyInterface')->getMockForAbstractClass();
        $revalidate = $this->getMockBuilder('Guzzle\Plugin\Cache\RevalidationInterface')->getMockForAbstractClass();
        $key = $this->getMockBuilder('Guzzle\Plugin\Cache\CacheKeyProviderInterface')->getMockForAbstractClass();
        $debugHeaders = false;
        $plugin = new CachePlugin(array(
            'storage' => $this->getMockBuilder('Guzzle\Plugin\Cache\CacheStorageInterface')->getMockForAbstractClass(),
            'can_cache'    => $can,
            'revalidation' => $revalidate,
            'key_provider' => $key,
            'debug_headers' => $debugHeaders,
        ));
        $this->assertSame($key, $this->readAttribute($plugin, 'keyProvider'));
        $this->assertSame($can, $this->readAttribute($plugin, 'canCache'));
        $this->assertSame($revalidate, $this->readAttribute($plugin, 'revalidation'));
        $this->assertSame($debugHeaders, $this->readAttribute($plugin, 'debugHeaders'));
    }

    public function satisfyProvider()
    {
        $req1 = new Request('GET', 'http://foo.com', array('Cache-Control' => 'no-cache'));
        $req2 = clone $req1;
        $req2->getParams()->set('cache.revalidate', 'skip');
        $req3 = clone $req1;
        $req3->getParams()->set('cache.revalidate', 'never');

        return array(
            // The response is too old to satisfy the request
            array(new Request('GET', 'http://foo.com', array('Cache-Control' => 'max-age=20')), new Response(200, array('Age' => 100)), false, false),
            // The response cannot satisfy the request because it is stale
            array(new Request('GET', 'http://foo.com'), new Response(200, array('Cache-Control' => 'max-age=10', 'Age' => 100)), false, false),
            // Allows the expired response to satisfy the request because of the max-stale
            array(new Request('GET', 'http://foo.com', array('Cache-Control' => 'max-stale=15')), new Response(200, array('Cache-Control' => 'max-age=90', 'Age' => 100)), true, false),
            // Max stale is > than the allowed staleness
            array(new Request('GET', 'http://foo.com', array('Cache-Control' => 'max-stale=5')), new Response(200, array('Cache-Control' => 'max-age=90', 'Age' => 100)), false, false),
            // Performs cache revalidation
            array($req1, new Response(200), true, true),
            // Does not perform revalidation
            array($req2, new Response(200), true, false),
            // Does not perform revalidation and fails
            array($req3, new Response(200), false, false),
        );
    }

    /**
     * @dataProvider satisfyProvider
     */
    public function testChecksIfResponseCanSatisfyRequest($request, $response, $can, $revalidates)
    {
        $didRevalidate = false;
        $revalidate = $this->getMockBuilder('Guzzle\Plugin\Cache\RevalidationInterface')
            ->setMethods(array('revalidate'))
            ->getMockForAbstractClass();

        $revalidate->expects($this->any())
            ->method('revalidate')
            ->will($this->returnCallback(function () use (&$didRevalidate) {
                $didRevalidate = true;
                return true;
            }));

        $plugin = new CachePlugin(array(
            'storage' => $this->getMockBuilder('Guzzle\Plugin\Cache\CacheStorageInterface')->getMockForAbstractClass(),
            'revalidation' => $revalidate
        ));

        $this->assertEquals($can, $plugin->canResponseSatisfyRequest($request, $response));
        $this->assertEquals($didRevalidate, $revalidates);
    }

    public function testDoesNothingWhenRequestIsNotCacheable()
    {
        $storage = $this->getMockBuilder('Guzzle\Plugin\Cache\CacheStorageInterface')
            ->setMethods(array('fetch'))
            ->getMockForAbstractClass();
        $storage->expects($this->never())->method('fetch');

        $plugin = new CachePlugin(array(
            'storage'   => $storage,
            'can_cache' => new CallbackCanCacheStrategy(function () { return false; })
        ));

        $plugin->onRequestBeforeSend(new Event(array(
            'request' => new Request('GET', 'http://foo.com')
        )));
    }

    public function satisfiableProvider()
    {
        $date = new \DateTime('-10 seconds');

        return array(
            // Fresh response adding debug headers
            array(
                true,
                array(200, array(), 'foo'),
            ),
            // Stale response adding debug headers
            array(
                true,
                array(200, array('Date' => $date->format('D, d M Y H:i:s T'), 'Cache-Control' => 'max-age=5'), 'foo'),
            ),
            // Fresh response not adding debug headers
            array(
                false,
                array(200, array(), 'foo'),
            ),
            // Stale response not adding debug headers
            array(
                false,
                array(200, array('Date' => $date->format('D, d M Y H:i:s T'), 'Cache-Control' => 'max-age=5'), 'foo'),
            ),
        );
    }

    /**
     * @dataProvider satisfiableProvider
     */
    public function testInjectsSatisfiableResponses($debugHeaders, $response)
    {
        $storage = $this->getMockBuilder('Guzzle\Plugin\Cache\CacheStorageInterface')
            ->setMethods(array('fetch'))
            ->getMockForAbstractClass();
        $storage->expects($this->once())->method('fetch')->will($this->returnValue($response));
        $plugin = new CachePlugin(array('storage' => $storage, 'debug_headers' => $debugHeaders));
        $request = new Request('GET', 'http://foo.com', array('Cache-Control' => 'max-stale'));
        $plugin->onRequestBeforeSend(new Event(array(
            'request' => $request
        )));
        $plugin->onRequestSent(
            new Event(array(
                'request' => $request,
                'response' => $request->getResponse(),
            ))
        );
        $this->assertEquals($response[0], $request->getResponse()->getStatusCode());
        $this->assertEquals($response[2], $request->getResponse()->getBody(true));
        $this->assertContains('key=', (string) $request->getResponse()->getHeader('X-Guzzle-Cache'));
        $this->assertTrue($request->getResponse()->hasHeader('Age'));
        if ($request->getResponse()->isFresh() === false) {
            $this->assertContains('110', $request->getResponse()->getHeader('Warning', true));
        }
        $this->assertSame(sprintf('%s GuzzleCache/%s', $request->getProtocolVersion(), Version::VERSION), $request->getHeader('Via', true));
        $this->assertSame(sprintf('%s GuzzleCache/%s', $request->getProtocolVersion(), Version::VERSION), $request->getResponse()->getHeader('Via', true));
        $this->assertTrue($request->getParams()->get('cache.lookup'));
        $this->assertTrue($request->getParams()->get('cache.hit'));

        if (!$debugHeaders) {
            $this->assertFalse($request->getResponse()->hasHeader('X-Cache-Lookup'));
            $this->assertFalse($request->getResponse()->hasHeader('X-Cache'));
        } else {
            $this->assertTrue($request->getResponse()->hasHeader('X-Cache-Lookup'));
            $this->assertTrue($request->getResponse()->hasHeader('X-Cache'));
            $this->assertEquals('HIT from GuzzleCache', $request->getResponse()->getHeader('X-Cache-Lookup', true));
            $this->assertEquals('HIT from GuzzleCache', $request->getResponse()->getHeader('X-Cache', true));
        }
    }

    public function testCachesResponsesWhenCacheable()
    {
        $cache = new ArrayCache();
        $adapter = new DoctrineCacheAdapter($cache);
        $plugin = new CachePlugin(array('adapter' => $adapter));

        $request = new Request('GET', 'http://foo.com');
        $response = new Response(200, array(), 'Foo');
        $plugin->onRequestBeforeSend(new Event(array(
            'request' => $request
        )));
        $plugin->onRequestSent(new Event(array(
            'request'  => $request,
            'response' => $response
        )));

        $data = $this->readAttribute($cache, 'data');
        $this->assertNotEmpty($data);
        $data = end($data);
        $this->assertEquals(200, $data[0]);
        $this->assertInternalType('array', $data[1]);
        $this->assertEquals('Foo', $data[2]);
    }
}
