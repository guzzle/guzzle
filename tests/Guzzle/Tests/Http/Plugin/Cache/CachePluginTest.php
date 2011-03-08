<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http\Plugin\Cache;

use Doctrine\Common\Cache\ArrayCache;
use Guzzle\Guzzle;
use Guzzle\Common\CacheAdapter\DoctrineCacheAdapter;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Plugin\Cache\CachePlugin;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CachePluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var ArrayCache
     */
    private $cache;

    /**
     * @var DoctrineCacheAdapter
     */
    private $adapter;

    /**
     * Remove node.js generated Connection: keep-alive header
     *
     * @param string $response Response
     *
     * @return string
     */
    protected function removeKeepAlive($response)
    {
        return str_replace("Connection: keep-alive\r\n", '', $response);
    }

    protected function setUp()
    {
        parent::setUp();
        $this->cache = new ArrayCache();
        $this->adapter = new DoctrineCacheAdapter($this->cache);
    }

    /**
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::__construct
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::getCacheAdapter
     */
    public function testConstructorSetsValues()
    {
        $plugin = new CachePlugin($this->adapter, true, true, 1200);
        
        $this->assertEquals($this->adapter, $plugin->getCacheAdapter());
    }

    /**
     * @covers Guzzle\Http\Plugin\AbstractPlugin::attach
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::update
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::attach
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::saveCache
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::getCacheKey
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::canResponseSatisfyRequest
     */
    public function testSavesResponsesInCache()
    {
        // Send a 200 OK script to the testing server
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");

        // Create a new Cache plugin
        $plugin = new CachePlugin($this->adapter, true);

        // Make sure that non GET and HEAD requests are not attached
        $request = RequestFactory::getInstance()->newRequest('POST', $this->getServer()->getUrl());
        $this->assertFalse($plugin->attach($request));
        
        // Create a new Request
        $request = RequestFactory::getInstance()->newRequest('GET', $this->getServer()->getUrl());
        $this->assertTrue($plugin->attach($request));
        
        // Send the Request to the test server
        $request->send();

        // Calculate the cache key like the cache plugin does
        $key = $plugin->getCacheKey($request);
        
        // Make sure that the cache plugin set the request in cache
        $this->assertNotNull($this->adapter->fetch($key));

        // Clear out the requests stored on the server to make sure we didn't send a new request
        $this->getServer()->flush();
        
        // Test that the request is set manually
        // The test server has no more script data, so if it actually sends a
        // request it will fail the test.
        $request2 = RequestFactory::getInstance()->newRequest('GET', $this->getServer()->getUrl());
        $this->assertTrue($plugin->attach($request2));
        $request2->send();
        $this->assertEquals('data', $request2->getResponse()->getBody(true));

        // Make sure a request wasn't sent
        $this->assertEquals(0, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::update
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::saveCache
     */
    public function testSkipsNonReadableResponseBodies()
    {
        // Send a 200 OK script to the testing server
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");

        // Create a new Cache plugin
        $plugin = new CachePlugin($this->adapter, true);

        // Create a new Client using the Cache plugin
        $request = RequestFactory::getInstance()->newRequest('GET', $this->getServer()->getUrl());
        $this->assertTrue($plugin->attach($request));

        // Create a temp file that is not readable
        $tempFile = tempnam('/tmp', 'temp_stream_data');
        // Set the non-readable stream as the response body so that it can't be cached
        $request->setResponseBody(EntityBody::factory(
            fopen($tempFile, 'w')
        ));

        $request->send();

        // Calculate the cache key like the cache plugin does
        $key = $plugin->getCacheKey($request);

        // Make sure that the cache plugin set the request in cache
        $this->assertFalse($this->adapter->fetch($key));

        // Clean up the test
        unset($request);
        unlink($tempFile);
    }

    public function cacheKeyDataProvider()
    {
        $r = array(
            array('', 'gzrq_http&www.test.com/path?q=abc&Host=www.test.com&Date=123', 'http://www.test.com/path?q=abc', "Host: Google.com\r\nDate: 123"),
            array('query = q', 'gzrq_http&www.test.com/path&Host=www.test.com&Date=123', 'http://www.test.com/path?q=abc', "Host: Google.com\r\nDate: 123"),
            array('query=q; header=Date;', 'gzrq_http&www.test.com/path&Host=www.test.com', 'http://www.test.com/path?q=abc', "Host: Google.com\r\nDate: 123"),
            array('query=a,  q; header=Date, Host;', 'gzrq_http&www.test.com/path&', 'http://www.test.com/path?q=abc&a=123', "Host: Google.com\r\nDate: 123"),
        );

        return $r;
    }

    /**
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::getCacheKey
     * @dataProvider cacheKeyDataProvider
     */
    public function testCreatesCacheKeysUsingFilters($filter, $key, $url, $headers = null)
    {
        // Create a new Cache plugin
        $plugin = new CachePlugin($this->adapter, true);

        // Generate the header array
        $h = null;
        if ($headers) {
            $h = array();
            foreach (explode("\r\n", $headers) as $header) {
                list($k, $v) = explode(': ', $header);
                $h[$k] = $v;
            }
        }

        // Create the request
        $request = RequestFactory::getInstance()->newRequest('GET', $url, $h);
        $request->getParams()->set('cache.key_filter', $filter);
        $request->removeHeader('User-Agent');

        $this->assertEquals($key, $plugin->getCacheKey($request, true));

        // Make sure that the encoded request is returned when $raw is false
        $this->assertNotEquals($key, $plugin->getCacheKey($request));
        
        unset($request);
        unset($plugin);
    }

    /**
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::getCacheKey
     */
    public function testCreatesEncodedKeys()
    {
        $plugin = new CachePlugin($this->adapter, true);
        $request = RequestFactory::getInstance()->createFromMessage(
            "GET / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, no-store, max-age=120"
        );

        $key = $plugin->getCacheKey($request);

        $this->assertEquals(1, preg_match('/^gzrq_[a-z0-9]{32}$/', $key));

        // Make sure that the same value is returned in a subsequent call
        $this->assertEquals($key, $plugin->getCacheKey($request));
    }

    /**
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::update
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::saveCache
     */
    public function testRequestsCanOverrideTtlUsingCacheParam()
    {
        $plugin = new CachePlugin($this->adapter, true);
        
        $request = new Request('GET', 'http://www.test.com/');
        $request->getParams()->set('cache.override_ttl', 1000);
        $plugin->attach($request);
        $request->setResponse(Response::factory("HTTP/1.1 200 OK\r\nCache-Control: max-age=100\r\nContent-Length: 4\r\n\r\nData"), true);
        $request->send();
        
        $request2 = new Request('GET', 'http://www.test.com/');
        $plugin->attach($request2);
        $response = $request2->send();

        $this->assertEquals(1000, $response->getHeader('X-Guzzle-Ttl'));
    }

    /**
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::canResponseSatisfyRequest
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::update
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::saveCache
     */
    public function testRequestsCanAcceptStaleResponses()
    {
        $server = $this->getServer();
        $plugin = new CachePlugin($this->adapter, true);

        $request = new Request('GET', $server->getUrl() . 'test');
        $plugin->attach($request);
        // Cache this response for 1000 seconds if it is cacheable
        $request->getParams()->set('cache.override_ttl', 1000);
        $request->setResponse(Response::factory("HTTP/1.1 200 OK\r\nExpires: " . Guzzle::getHttpDate('-1 second') . "\r\nContent-Length: 4\r\n\r\nData"), true);
        $request->send();

        sleep(1);
        
        // Accept responses that are up to 100 seconds expired
        $request2 = new Request('GET', $server->getUrl() . 'test');
        $plugin->attach($request2);
        $request2->addCacheControlDirective('max-stale', 100);
        $response = $request2->send();
        $this->assertEquals(1000, $response->getHeader('X-Guzzle-Ttl'));

        // Accepts any stale response
        $request3 = new Request('GET', $server->getUrl() . 'test');
        $request3->addCacheControlDirective('max-stale');
        $plugin->attach($request3);
        $response = $request3->send();
        $this->assertEquals(1000, $response->getHeader('X-Guzzle-Ttl'));

        // Will not accept the stale cached entry
        $server->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nData");
        $request4 = new Request('GET', $server->getUrl() . 'test');
        $request4->addCacheControlDirective('max-stale', 0);
        $plugin->attach($request4);
        $response = $request4->send();
        $this->assertEquals("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nData", $this->removeKeepAlive((string) $response));
    }

    /**
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::canResponseSatisfyRequest
     */
    public function testChecksIfResponseCanSatisfyRequest()
    {
        $plugin = new CachePlugin($this->adapter, true);

        // Send some responses to the test server for cache validation
        $server = $this->getServer();
        
        // No restrictions
        $request = RequestFactory::getInstance()->newRequest('GET', $server->getUrl());
        $response = new Response(200, array('Date' => Guzzle::getHttpDate('now')));
        $this->assertTrue($plugin->canResponseSatisfyRequest($request, $response));

        // Request max-age is less than response age
        $request = RequestFactory::getInstance()->newRequest('GET', $server->getUrl());
        $request->addCacheControlDirective('max-age', 100);
        $response = new Response(200, array('Age' => 10));
        $this->assertTrue($plugin->canResponseSatisfyRequest($request, $response));

        // Request must have something fresher than 200 seconds
        $response->setHeader('Date', Guzzle::getHttpDate('-200 days'));
        $response->removeHeader('Age');
        $request->setHeader('Cache-Control', 'max-age=200');
        $this->assertFalse($plugin->canResponseSatisfyRequest($request, $response));

        // Response says it's too old
        $request->removeHeader('Cache-Control');
        $response->setHeader('Cache-Control', 'max-age=86400');
        $this->assertFalse($plugin->canResponseSatisfyRequest($request, $response));

        // Response is OK
        $response->setHeader('Date', Guzzle::getHttpDate('-1 hour'));
        $this->assertTrue($plugin->canResponseSatisfyRequest($request, $response));
    }

    /**
     * Data provider to test cache revalidation
     *
     * @return array
     */
    public function cacheRevalidationDataProvider()
    {
        return array(
            // Forces revalidation that passes
            array(
                true,
                "Pragma: no-cache\r\n\r\n",
                "HTTP/1.1 200 OK\r\nDate: " . Guzzle::getHttpDate('-100 hours') . "\r\nContent-Length: 4\r\n\r\nData",
                "HTTP/1.1 304 NOT MODIFIED\r\nCache-Control: max-age=2000000\r\n\r\n",
            ),
            // Forces revalidation that overwrites what is in cache
            array(
                false,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: must-revalidate, no-cache\r\nDate: " . Guzzle::getHttpDate('-10 hours') . "\r\nContent-Length: 4\r\n\r\nData",
                "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nDatas",
                "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nDate: " . Guzzle::getHttpDate('now') . "\r\n\r\nDatas"
            ),
            // Skips revalidation because the request is accepting the cached copy
            array(
                false,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: no-cache\r\nDate: " . Guzzle::getHttpDate('-3 hours') . "\r\nContent-Length: 4\r\n\r\nData",
                null,
                null,
                'decline'
            ),
            // Must get a fresh copy because the request is declining revalidation
            array(
                true,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: no-cache\r\nDate: " . Guzzle::getHttpDate('-3 hours') . "\r\nContent-Length: 4\r\n\r\nData",
                null,
                null,
                'accept'
            ),
            // Throws an exception during revalidation
            array(
                false,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: no-cache\r\nDate: " . Guzzle::getHttpDate('-3 hours') . "\r\n\r\nData",
                "HTTP/1.1 500 INTERNAL SERVER ERROR\r\nContent-Length: 0\r\n\r\n"
            ),
            // ETag mismatch
            array(
                false,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: no-cache\r\nETag: \"123\"\r\nDate: " . Guzzle::getHttpDate('-10 hours') . "\r\n\r\nData",
                "HTTP/1.1 304 NOT MODIFIED\r\nETag: \"123456\"\r\n\r\n",
            ),
        );
    }

    /**
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::canResponseSatisfyRequest
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin::revalidate
     * @dataProvider cacheRevalidationDataProvider
     */
    public function testRevalidatesResponsesAgainstOriginServer($can, $request, $response, $validate = null, $result = null, $param = null)
    {
        // Send some responses to the test server for cache validation
        $server = $this->getServer();
        $plugin = new CachePlugin($this->adapter, true);
        $server->flush();

        if ($validate) {
            $server->enqueue($validate);
        }

        $request = RequestFactory::getInstance()->createFromMessage("GET / HTTP/1.1\r\nHost: 127.0.0.1:" . $server->getPort() . "\r\n" . $request);
        
        if ($param) {
            $request->getParams()->set('cache.revalidate', $param);
        }

        $response = Response::factory($response);        
        $this->assertEquals($can, $plugin->canResponseSatisfyRequest($request, $response));
        
        if ($result) {
            // Get rid of dates
            $this->assertEquals(
                preg_replace('/(Date:\s)(.*)(\r\n)/', '$1$3', (string) $result),
                preg_replace('/(Date:\s)(.*)(\r\n)/', '$1$3', (string) $request->getResponse())
            );
        }

        if ($validate) {
            $this->assertEquals(1, count($server->getReceivedRequests()));
        }
    }

    /**
     * @covers Guzzle\Http\Plugin\Cache\CachePlugin
     */
    public function testCachesResponsesAndHijacksRequestsWhenApplicable()
    {
        $plugin = new CachePlugin($this->adapter, true);
        $server = $this->getServer();
        $server->enqueue("HTTP/1.1 200 OK\r\nCache-Control: max-age=1000\r\nContent-Length: 4\r\n\r\nData");
        
        $request = new Request('GET', $server->getUrl());
        $request->getCurlOptions()->set(\CURLOPT_TIMEOUT, 2);
        $plugin->attach($request);

        $request2 = new Request('GET', $server->getUrl());
        $request2->getCurlOptions()->set(\CURLOPT_TIMEOUT, 2);
        $plugin->attach($request2);

        $request->send();
        $request2->send();

        $this->assertTrue($request2->getResponse()->hasHeader('X-Guzzle-Cache'));
    }
}