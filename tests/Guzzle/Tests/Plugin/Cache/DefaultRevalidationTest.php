<?php

namespace Guzzle\Tests\Plugin\Cache;

use Guzzle\Http\Client;
use Guzzle\Http\Exception\BadResponseException;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Utils;
use Guzzle\Plugin\Cache\CachePlugin;
use Guzzle\Plugin\Cache\DefaultRevalidation;
use Guzzle\Cache\DoctrineCacheAdapter;
use Guzzle\Plugin\Cache\CallbackCacheKeyProvider;
use Doctrine\Common\Cache\ArrayCache;
use Guzzle\Plugin\Cache\DefaultCacheStorage;

/**
 * @covers Guzzle\Plugin\Cache\DefaultRevalidation
 * @group server
 */
class DefaultRevalidationTest extends \Guzzle\Tests\GuzzleTestCase
{
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

        $plugin = new CachePlugin(new DoctrineCacheAdapter(new ArrayCache()));
        $this->assertEquals($can, $plugin->canResponseSatisfyRequest($request, $response), '-> ' . $request . "\n" . $response);

        if ($result) {
            $result = Response::fromMessage($result);
            // Get rid of the X-Guzzle-Cache header
            $this->assertTrue($request->getResponse()->hasHeader('X-Guzzle-Cache'));
            $result->removeHeader('X-Guzzle-Cache');
            $request->getResponse()->removeHeader('X-Guzzle-Cache');
            // Get rid of dates
            $this->assertTrue($result->hasHeader('Date'));
            $this->assertTrue($request->getResponse()->hasHeader('Date'));
            $result->removeHeader('Date');
            $request->getResponse()->removeHeader('Date');
            // Get rid of dates
            $this->assertEquals((string) $result, (string) $request->getResponse());
        }

        if ($validate) {
            $this->assertEquals(1, count($server->getReceivedRequests()));
        }
    }

    public function testHandles404RevalidationResponses()
    {
        $request = new Request('GET', 'http://foo.com');
        $request->setClient(new Client());
        $badResponse = new Response(404, array(), 'Oh no!');
        $badRequest = clone $request;
        $badRequest->setResponse($badResponse, true);
        $response = new Response(200, array(), 'foo');

        $c = new ArrayCache();
        $c->save('foo', array(200, array(), 'foo'));
        $s = new DefaultCacheStorage(new DoctrineCacheAdapter($c));
        $k = new CallbackCacheKeyProvider(function () { return 'foo'; });

        $rev = $this->getMockBuilder('Guzzle\Plugin\Cache\DefaultRevalidation')
            ->setConstructorArgs(array($k, $s))
            ->setMethods(array('createRevalidationRequest'))
            ->getMock();

        $rev->expects($this->once())
            ->method('createRevalidationRequest')
            ->will($this->returnValue($badRequest));

        try {
            $rev->revalidate($request, $response);
            $this->fail('Should have thrown an exception');
        } catch (BadResponseException $e) {
            $this->assertSame($badResponse, $e->getResponse());
            $this->assertFalse($c->fetch('foo'));
        }
    }
}
