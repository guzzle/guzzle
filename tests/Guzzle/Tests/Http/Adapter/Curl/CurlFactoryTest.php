<?php

namespace Guzzle\Tests\Http\Adapter\Curl;

require_once __DIR__ . '/../../Server.php';

use Guzzle\Http\Adapter\Curl\CurlAdapter;
use Guzzle\Stream\Stream;
use Guzzle\Tests\Http\Server;
use Guzzle\Http\Adapter\Curl\CurlFactory;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Client;
use Guzzle\Http\Message\MessageFactory;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\RequestInterface;

/**
 * @covers Guzzle\Http\Adapter\Curl\CurlFactory
 */
class CurlFactoryTest extends \PHPUnit_Framework_TestCase
{
    /** @var \Guzzle\Tests\Http\Server */
    static $server;

    public static function setUpBeforeClass()
    {
        self::$server = new Server();
        self::$server->start();
    }

    public static function tearDownAfterClass()
    {
        self::$server->stop();
    }

    public function testCreatesCurlHandle()
    {
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nFoo: Bar\r\n Baz:  bam\r\nContent-Length: 2\r\n\r\nhi"]);
        $request = new Request('PUT', self::$server->getUrl() . 'haha', ['Hi' => ' 123'], Stream::factory('testing'));
        $stream = Stream::factory();
        $request->getConfig()->set('save_to', $stream);
        $request->getConfig()->set('verify', true);

        $t = new Transaction(new Client(), $request);
        $f = new IntroFactory();
        $h = $f->createHandle($t, new MessageFactory());
        $this->assertInternalType('resource', $h);
        curl_exec($h);
        $response = $t->getResponse();
        $this->assertInstanceOf('Guzzle\Http\Message\ResponseInterface', $response);
        $this->assertEquals('hi', $response->getBody());
        $this->assertEquals('Bar', $response->getHeader('Foo'));
        $this->assertEquals('bam', $response->getHeader('Baz'));
        curl_close($h);

        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertEquals('PUT', $sent->getMethod());
        $this->assertEquals('/haha', $sent->getPath());
        $this->assertEquals('123', $sent->getHeader('Hi'));
        $this->assertEquals('7', $sent->getHeader('Content-Length'));
        $this->assertEquals('testing', $sent->getBody());
        $this->assertEquals('1.1', $sent->getProtocolVersion());
        $this->assertEquals('hi', (string) $stream);

        $this->assertEquals(2, $f->last[CURLOPT_SSL_VERIFYHOST]);
        $this->assertEquals(true, $f->last[CURLOPT_SSL_VERIFYPEER]);
    }

    public function testSendsHeadRequests()
    {
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n"]);
        $request = new Request('HEAD', self::$server->getUrl());

        $t = new Transaction(new Client(), $request);
        $f = new CurlFactory();
        $h = $f->createHandle($t, new MessageFactory());
        curl_exec($h);
        curl_close($h);
        $response = $t->getResponse();
        $this->assertEquals('2', $response->getHeader('Content-Length'));
        $this->assertEquals('', $response->getBody());

        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertEquals('HEAD', $sent->getMethod());
        $this->assertEquals('/', $sent->getPath());
    }

    public function testSendsPostRequestWithNoBody()
    {
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"]);
        $request = new Request('POST', self::$server->getUrl());
        $t = new Transaction(new Client(), $request);
        $h = (new CurlFactory())->createHandle($t, new MessageFactory());
        curl_exec($h);
        curl_close($h);
        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertEquals('POST', $sent->getMethod());
        $this->assertEquals('', $sent->getBody());
    }

    public function testSendsChunkedRequests()
    {
        $stream = $this->getMockBuilder('Guzzle\Stream\Stream')
            ->setConstructorArgs([fopen('php://temp', 'r+')])
            ->setMethods(['getSize'])
            ->getMock();
        $stream->expects($this->any())
            ->method('getSize')
            ->will($this->returnValue(null));
        $stream->write('foo');
        $stream->seek(0);

        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"]);
        $request = new Request('PUT', self::$server->getUrl(), [], $stream);
        $this->assertNull($request->getBody()->getSize());
        $t = new Transaction(new Client(), $request);
        $h = (new CurlFactory())->createHandle($t, new MessageFactory());
        curl_exec($h);
        curl_close($h);
        $sent = self::$server->getReceivedRequests(false)[0];
        $this->assertContains('PUT / HTTP/1.1', $sent);
        $this->assertContains('transfer-encoding: chunked', strtolower($sent));
        $this->assertContains("\r\n\r\nfoo", $sent);
    }

    public function testDecodesGzippedResponses()
    {
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nfoo"]);
        $request = new Request('GET', self::$server->getUrl(), ['Accept-Encoding' => '']);
        $t = new Transaction(new Client(), $request);
        $h = (new CurlFactory())->createHandle($t, new MessageFactory());
        curl_exec($h);
        curl_close($h);
        $this->assertEquals('foo', $t->getResponse()->getBody());
        $sent = self::$server->getReceivedRequests(true)[0];
        $this->assertContains('gzip', $sent->getHeader('Accept-Encoding'));
    }

    public function testAddsDebugInfoToBuffer()
    {
        $r = fopen('php://temp', 'r+');
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nfoo"]);
        $request = new Request('GET', self::$server->getUrl());
        $request->getConfig()->set('debug', $r);
        $t = new Transaction(new Client(), $request);
        $h = (new CurlFactory())->createHandle($t, new MessageFactory());
        curl_exec($h);
        curl_close($h);
        rewind($r);
        $this->assertNotEmpty(stream_get_contents($r));
    }

    public function testAddsProxyOptions()
    {
        $request = new Request('GET', self::$server->getUrl());
        $request->getConfig()->set('proxy', '123');
        $request->getConfig()->set('connect_timeout', 1);
        $request->getConfig()->set('timeout', 2);
        $request->getConfig()->set('cert', __FILE__);
        $request->getConfig()->set('ssl_key', [__FILE__, '123']);
        $request->getConfig()->set('verify', false);
        $t = new Transaction(new Client(), $request);
        $f = new IntroFactory();
        curl_close($f->createHandle($t, new MessageFactory()));
        $this->assertEquals('123', $f->last[CURLOPT_PROXY]);
        $this->assertEquals(1000, $f->last[CURLOPT_CONNECTTIMEOUT_MS]);
        $this->assertEquals(2000, $f->last[CURLOPT_TIMEOUT_MS]);
        $this->assertEquals(__FILE__, $f->last[CURLOPT_SSLCERT]);
        $this->assertEquals(__FILE__, $f->last[CURLOPT_SSLKEY]);
        $this->assertEquals('123', $f->last[CURLOPT_SSLKEYPASSWD]);
        $this->assertEquals(0, $f->last[CURLOPT_SSL_VERIFYHOST]);
        $this->assertEquals(false, $f->last[CURLOPT_SSL_VERIFYPEER]);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testEnsuresCertExists()
    {
        $request = new Request('GET', self::$server->getUrl());
        $request->getConfig()->set('cert', __FILE__ . 'ewfwef');
        (new IntroFactory())->createHandle(new Transaction(new Client(), $request), new MessageFactory());
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testEnsuresKeyExists()
    {
        $request = new Request('GET', self::$server->getUrl());
        $request->getConfig()->set('ssl_key', __FILE__ . 'ewfwef');
        (new IntroFactory())->createHandle(new Transaction(new Client(), $request), new MessageFactory());
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testEnsuresCacertExists()
    {
        $request = new Request('GET', self::$server->getUrl());
        $request->getConfig()->set('verify', __FILE__ . 'ewfwef');
        (new IntroFactory())->createHandle(new Transaction(new Client(), $request), new MessageFactory());
    }

    public function testClientUsesSslByDefault()
    {
        self::$server->flush();
        self::$server->enqueue(["HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\nfoo"]);
        $f = new IntroFactory();
        $client = new Client([
            'base_url' => self::$server->getUrl(),
            'adapter' => new CurlAdapter(new MessageFactory(), ['handle_factory' => $f])
        ]);
        $client->get();
        $this->assertEquals(2, $f->last[CURLOPT_SSL_VERIFYHOST]);
        $this->assertEquals(true, $f->last[CURLOPT_SSL_VERIFYPEER]);
        $this->assertFileExists($f->last[CURLOPT_CAINFO]);
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid authentication scheme: foo
     */
    public function testValidatesAuthType()
    {
        $request = new Request('GET', self::$server->getUrl());
        $request->getConfig()->set('auth', ['a', 'b', 'foo']);
        (new IntroFactory())->createHandle(new Transaction(new Client(), $request), new MessageFactory());
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage auth must be an array that contains a username and password
     */
    public function testValidatesAuthArray()
    {
        $request = new Request('GET', self::$server->getUrl());
        $request->getConfig()->set('auth', 'foo');
        (new IntroFactory())->createHandle(new Transaction(new Client(), $request), new MessageFactory());
    }

    public function testAddsAuth()
    {
        $request = new Request('GET', self::$server->getUrl());
        $request->getConfig()->set('auth', ['a', 'b', 'digest']);
        $f = new IntroFactory();
        curl_close($f->createHandle(new Transaction(new Client(), $request), new MessageFactory()));
        $this->assertEquals('a:b', $f->last[CURLOPT_USERPWD]);
        $this->assertEquals(CURLAUTH_DIGEST, $f->last[CURLOPT_HTTPAUTH]);
    }

    public function testConvertsConstantNameKeysToValues()
    {
        $request = new Request('GET', self::$server->getUrl());
        $request->getConfig()->set('curl', ['CURLOPT_USERAGENT' => 'foo']);
        $f = new IntroFactory();
        curl_close($f->createHandle(new Transaction(new Client(), $request), new MessageFactory()));
        $this->assertEquals('foo', $f->last[CURLOPT_USERAGENT]);
    }

    public function testStripsFragment()
    {
        $request = new Request('GET', self::$server->getUrl() . '#foo');
        $f = new IntroFactory();
        curl_close($f->createHandle(new Transaction(new Client(), $request), new MessageFactory()));
        $this->assertEquals(self::$server->getUrl(), $f->last[CURLOPT_URL]);
    }

    public function testDoesNotSendSizeTwice()
    {
        $request = new Request('PUT', self::$server->getUrl(), [], Stream::factory(str_repeat('a', 32769)));
        $f = new IntroFactory();
        curl_close($f->createHandle(new Transaction(new Client(), $request), new MessageFactory()));
        $this->assertEquals(32769, $f->last[CURLOPT_INFILESIZE]);
        $this->assertNotContains('Content-Length', implode(' ', $f->last[CURLOPT_HTTPHEADER]));

    }
}

class IntroFactory extends CurlFactory
{
    public $last;

    protected function applyHeaders(RequestInterface $request, array &$options)
    {
        parent::applyHeaders($request, $options);
        $this->last = $options;
    }

    protected function applyCustomCurlOptions(array $config, array $options)
    {
        return $this->last = parent::applyCustomCurlOptions($config, $options);
    }
}
