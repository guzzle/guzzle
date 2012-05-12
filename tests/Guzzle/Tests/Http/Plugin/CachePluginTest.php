<?php

namespace Guzzle\Tests\Http\Plugin;

use Doctrine\Common\Cache\ArrayCache;
use Guzzle\Common\Cache\DoctrineCacheAdapter;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Client;
use Guzzle\Http\Utils;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Plugin\CachePlugin;

/**
 * @group server
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
     * @covers Guzzle\Http\Plugin\CachePlugin::__construct
     * @covers Guzzle\Http\Plugin\CachePlugin::getCacheAdapter
     */
    public function testConstructorSetsValues()
    {
        $plugin = new CachePlugin($this->adapter, true, true, 1200);

        $this->assertEquals($this->adapter, $plugin->getCacheAdapter());
    }

    /**
     * @covers Guzzle\Http\Plugin\CachePlugin::onRequestSent
     * @covers Guzzle\Http\Plugin\CachePlugin::onRequestBeforeSend
     * @covers Guzzle\Http\Plugin\CachePlugin::saveCache
     * @covers Guzzle\Http\Plugin\CachePlugin::getCacheKey
     * @covers Guzzle\Http\Plugin\CachePlugin::canResponseSatisfyRequest
     */
    public function testSavesResponsesInCache()
    {
        // Send a 200 OK script to the testing server
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata",
            "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ntest"
        ));

        // Create a new Cache plugin
        $plugin = new CachePlugin($this->adapter, true);
        $client = new Client($this->getServer()->getUrl());
        $client->setCurlMulti(new \Guzzle\Http\Curl\CurlMulti());
        $client->getEventDispatcher()->addSubscriber($plugin);

        $request = $client->get();
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
        $this->assertEquals($key, $plugin->getCacheKey($request));
        $request->setState('new');
        $request->send();
        $this->assertEquals('data', $request->getResponse()->getBody(true));

        // Make sure a request wasn't sent
        $this->assertEquals(0, count($this->getServer()->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Plugin\CachePlugin::onRequestSent
     * @covers Guzzle\Http\Plugin\CachePlugin::onRequestBeforeSend
     * @covers Guzzle\Http\Plugin\CachePlugin::saveCache
     */
    public function testSkipsNonReadableResponseBodies()
    {
        // Send a 200 OK script to the testing server
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");

        // Create a new Cache plugin
        $plugin = new CachePlugin($this->adapter, true);
        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);

        // Create a new request using the Cache plugin
        $request = $client->get();

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
    }

    public function cacheKeyDataProvider()
    {
        $r = array(
            array('', 'gz_http_www.test.com/path?q=abc_host=www.test.com&date=123', 'http://www.test.com/path?q=abc', "Host: www.test.com\r\nDate: 123"),
            array('query = q', 'gz_http_www.test.com/path_host=www.test.com&date=123', 'http://www.test.com/path?q=abc', "Host: www.test.com\r\nDate: 123"),
            array('query=q; header=Date;', 'gz_http_www.test.com/path_host=www.test.com', 'http://www.test.com/path?q=abc', "Host: www.test.com\r\nDate: 123"),
            array('query=a,  q; header=Date, Host;', 'gz_http_www.test.com/path_', 'http://www.test.com/path?q=abc&a=123', "Host: www.test.com\r\nDate: 123"),
        );

        return $r;
    }

    /**
     * @covers Guzzle\Http\Plugin\CachePlugin::getCacheKey
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
        $request = RequestFactory::getInstance()->create('GET', $url, $h);
        $request->getParams()->set('cache.key_filter', $filter);
        $request->removeHeader('User-Agent');

        $this->assertEquals($key, $plugin->getCacheKey($request, true));

        // Make sure that the encoded request is returned when $raw is false
        $this->assertNotEquals($key, $plugin->getCacheKey($request));
    }

    /**
     * @covers Guzzle\Http\Plugin\CachePlugin::getCacheKey
     */
    public function testCreatesEncodedKeys()
    {
        $plugin = new CachePlugin($this->adapter, true);
        $request = RequestFactory::getInstance()->fromMessage(
            "GET / HTTP/1.1\r\nHost: www.test.com\r\nCache-Control: no-cache, no-store, max-age=120"
        );

        $key = $plugin->getCacheKey($request);

        $this->assertEquals(1, preg_match('/^gz_[a-z0-9]{32}$/', $key));

        // Make sure that the same value is returned in a subsequent call
        $this->assertEquals($key, $plugin->getCacheKey($request));
    }

    /**
     * @covers Guzzle\Http\Plugin\CachePlugin::onRequestSent
     * @covers Guzzle\Http\Plugin\CachePlugin::onRequestBeforeSend
     * @covers Guzzle\Http\Plugin\CachePlugin::saveCache
     */
    public function testRequestsCanOverrideTtlUsingCacheParam()
    {
        $plugin = new CachePlugin($this->adapter, true);
        $client = new Client($this->getServer()->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);

        $request = $client->get('http://www.test.com/');
        $request->getParams()->set('cache.override_ttl', 1000);
        $request->setResponse(Response::fromMessage("HTTP/1.1 200 OK\r\nCache-Control: max-age=100\r\nContent-Length: 4\r\n\r\nData"), true);
        $request->send();

        $request2 = $client->get('http://www.test.com/');
        $response = $request2->send();

        $token = $response->getTokenizedHeader('X-Guzzle-Cache', ', ');
        $this->assertEquals(1000, $token['ttl']);
        $this->assertEquals(true, $token->hasKey('key'));
    }

    /**
     * @covers Guzzle\Http\Plugin\CachePlugin::canResponseSatisfyRequest
     * @covers Guzzle\Http\Plugin\CachePlugin::onRequestSent
     * @covers Guzzle\Http\Plugin\CachePlugin::onRequestBeforeSend
     * @covers Guzzle\Http\Plugin\CachePlugin::saveCache
     */
    public function testRequestsCanAcceptStaleResponses()
    {
        $server = $this->getServer();

        $client = new Client($this->getServer()->getUrl());
        $plugin = new CachePlugin($this->adapter, true);
        $client->getEventDispatcher()->addSubscriber($plugin);

        $request = $client->get('test');
        // Cache this response for 1000 seconds if it is cacheable
        $request->getParams()->set('cache.override_ttl', 1000);
        $request->setResponse(Response::fromMessage("HTTP/1.1 200 OK\r\nExpires: " . Utils::getHttpDate('-1 second') . "\r\nContent-Length: 4\r\n\r\nData"), true);
        $request->send();

        sleep(1);

        // Accept responses that are up to 100 seconds expired
        $request2 = $client->get('test');
        $request2->addCacheControlDirective('max-stale', 100);
        $response = $request2->send();
        $token = $response->getTokenizedHeader('X-Guzzle-Cache', ', ');
        $this->assertEquals(1000, $token['ttl']);

        // Accepts any stale response
        $request3 = $client->get('test');
        $request3->addCacheControlDirective('max-stale');
        $response = $request3->send();
        $token = $response->getTokenizedHeader('X-Guzzle-Cache', ', ');
        $this->assertEquals(1000, $token['ttl']);

        // Will not accept the stale cached entry
        $server->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nData");
        $request4 = $client->get('test');
        $request4->addCacheControlDirective('max-stale', 0);
        $response = $request4->send();
        $this->assertEquals("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nData", $this->removeKeepAlive((string) $response));
    }

    /**
     * @covers Guzzle\Http\Plugin\CachePlugin::canResponseSatisfyRequest
     */
    public function testChecksIfResponseCanSatisfyRequest()
    {
        $plugin = new CachePlugin($this->adapter, true);

        // Send some responses to the test server for cache validation
        $server = $this->getServer();

        // No restrictions
        $request = RequestFactory::getInstance()->create('GET', $server->getUrl());
        $response = new Response(200, array('Date' => Utils::getHttpDate('now')));
        $this->assertTrue($plugin->canResponseSatisfyRequest($request, $response));

        // Request max-age is less than response age
        $request = RequestFactory::getInstance()->create('GET', $server->getUrl());
        $request->addCacheControlDirective('max-age', 100);
        $response = new Response(200, array('Age' => 10));
        $this->assertTrue($plugin->canResponseSatisfyRequest($request, $response));

        // Request must have something fresher than 200 seconds
        $response->setHeader('Date', Utils::getHttpDate('-200 days'));
        $response->removeHeader('Age');
        $request->setHeader('Cache-Control', 'max-age=200');
        $this->assertFalse($plugin->canResponseSatisfyRequest($request, $response));

        // Response says it's too old
        $request->removeHeader('Cache-Control');
        $response->setHeader('Cache-Control', 'max-age=86400');
        $this->assertFalse($plugin->canResponseSatisfyRequest($request, $response));

        // Response is OK
        $response->setHeader('Date', Utils::getHttpDate('-1 hour'));
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
                "HTTP/1.1 200 OK\r\nDate: " . Utils::getHttpDate('-100 hours') . "\r\nContent-Length: 4\r\n\r\nData",
                "HTTP/1.1 304 NOT MODIFIED\r\nCache-Control: max-age=2000000\r\nContent-Length: 0\r\n\r\n",
            ),
            // Forces revalidation that overwrites what is in cache
            array(
                false,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: must-revalidate, no-cache\r\nDate: " . Utils::getHttpDate('-10 hours') . "\r\nContent-Length: 4\r\n\r\nData",
                "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nDatas",
                "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nDate: " . Utils::getHttpDate('now') . "\r\n\r\nDatas"
            ),
            // Must get a fresh copy because the request is declining revalidation
            array(
                false,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: no-cache\r\nDate: " . Utils::getHttpDate('-3 hours') . "\r\nContent-Length: 4\r\n\r\nData",
                null,
                null,
                'never'
            ),
            // Skips revalidation because the request is accepting the cached copy
            array(
                true,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: no-cache\r\nDate: " . Utils::getHttpDate('-3 hours') . "\r\nContent-Length: 4\r\n\r\nData",
                null,
                null,
                'skip'
            ),
            // Throws an exception during revalidation
            array(
                false,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: no-cache\r\nDate: " . Utils::getHttpDate('-3 hours') . "\r\n\r\nData",
                "HTTP/1.1 500 INTERNAL SERVER ERROR\r\nContent-Length: 0\r\n\r\n"
            ),
            // ETag mismatch
            array(
                false,
                "\r\n\r\n",
                "HTTP/1.1 200 OK\r\nCache-Control: no-cache\r\nETag: \"123\"\r\nDate: " . Utils::getHttpDate('-10 hours') . "\r\n\r\nData",
                "HTTP/1.1 304 NOT MODIFIED\r\nETag: \"123456\"\r\n\r\n",
            ),
        );
    }

    /**
     * @covers Guzzle\Http\Plugin\CachePlugin::canResponseSatisfyRequest
     * @covers Guzzle\Http\Plugin\CachePlugin::revalidate
     * @dataProvider cacheRevalidationDataProvider
     */
    public function testRevalidatesResponsesAgainstOriginServer($can, $request, $response, $validate = null, $result = null, $param = null)
    {
        // Send some responses to the test server for cache validation
        $server = $this->getServer();
        $server->flush();

        if ($validate) {
            $server->enqueue($validate);
        }

        $request = RequestFactory::getInstance()->fromMessage("GET / HTTP/1.1\r\nHost: 127.0.0.1:" . $server->getPort() . "\r\n" . $request);
        $response = Response::fromMessage($response);
        $request->setClient(new Client());

        if ($param) {
            $request->getParams()->set('cache.revalidate', $param);
        }

        $plugin = new CachePlugin($this->adapter, true);
        $this->assertEquals($can, $plugin->canResponseSatisfyRequest($request, $response), '-> ' . $request . "\n" . $response);

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
     * @covers Guzzle\Http\Plugin\CachePlugin
     */
    public function testCachesResponsesAndHijacksRequestsWhenApplicable()
    {
        $server = $this->getServer();
        $server->flush();
        $server->enqueue("HTTP/1.1 200 OK\r\nCache-Control: max-age=1000\r\nContent-Length: 4\r\n\r\nData");

        $plugin = new CachePlugin($this->adapter, true);
        $client = new Client($server->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);

        $request = $client->get();
        $request->getCurlOptions()->set(CURLOPT_TIMEOUT, 2);
        $request2 = $client->get();
        $request2->getCurlOptions()->set(CURLOPT_TIMEOUT, 2);
        $request->send();
        $request2->send();

        $this->assertEquals(1, count($server->getReceivedRequests()));
        $this->assertEquals(true, $request2->getResponse()->hasHeader('X-Guzzle-Cache'));
    }

    /**
     * @covers Guzzle\Http\Plugin\CachePlugin::revalidate
     * @expectedException Guzzle\Http\Exception\BadResponseException
     */
    public function testRemovesMissingEntitesFromCacheWhenRevalidating()
    {
        $server = $this->getServer();
        $server->enqueue(array(
            "HTTP/1.1 200 OK\r\nCache-Control: max-age=1000, no-cache\r\nContent-Length: 4\r\n\r\nData",
            "HTTP/1.1 404 NOT FOUND\r\nContent-Length: 0\r\n\r\n"
        ));

        $plugin = new CachePlugin($this->adapter, true);
        $client = new Client($server->getUrl());
        $client->getEventDispatcher()->addSubscriber($plugin);

        $request1 = $client->get('/');
        $request1->send();
        $this->assertTrue($this->cache->contains($plugin->getCacheKey($request1)));
        $client->get('/')->send();
    }
}
