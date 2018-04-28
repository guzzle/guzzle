<?php
namespace GuzzleHttp\Test\Handler;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GuzzleHttp\Handler\MockHandler
 */
class MockHandlerTest extends TestCase
{
    public function testReturnsMockResponse()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, []);
        $this->assertSame($res, $p->wait());
    }

    public function testIsCountable()
    {
        $res = new Response();
        $mock = new MockHandler([$res, $res]);
        $this->assertCount(2, $mock);
    }

    public function testEmptyHandlerIsCountable()
    {
        $this->assertCount(0, new MockHandler());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresEachAppendIsValid()
    {
        $mock = new MockHandler(['a']);
        $request = new Request('GET', 'http://example.com');
        $mock($request, []);
    }

    public function testCanQueueExceptions()
    {
        $e = new \Exception('a');
        $mock = new MockHandler([$e]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, []);
        try {
            $p->wait();
            $this->fail();
        } catch (\Exception $e2) {
            $this->assertSame($e, $e2);
        }
    }

    public function testCanGetLastRequestAndOptions()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $mock($request, ['foo' => 'bar']);
        $this->assertSame($request, $mock->getLastRequest());
        $this->assertEquals(['foo' => 'bar'], $mock->getLastOptions());
    }

    public function testSinkFilename()
    {
        $filename = sys_get_temp_dir().'/mock_test_'.uniqid();
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $filename]);
        $p->wait();

        $this->assertFileExists($filename);
        $this->assertStringEqualsFile($filename, 'TEST CONTENT');

        unlink($filename);
    }

    public function testSinkResource()
    {
        $file = tmpfile();
        $meta = stream_get_meta_data($file);
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $file]);
        $p->wait();

        $this->assertFileExists($meta['uri']);
        $this->assertStringEqualsFile($meta['uri'], 'TEST CONTENT');
    }

    public function testSinkStream()
    {
        $stream = new \GuzzleHttp\Psr7\Stream(tmpfile());
        $res = new Response(200, [], 'TEST CONTENT');
        $mock = new MockHandler([$res]);
        $request = new Request('GET', '/');
        $p = $mock($request, ['sink' => $stream]);
        $p->wait();

        $this->assertFileExists($stream->getMetadata('uri'));
        $this->assertStringEqualsFile($stream->getMetadata('uri'), 'TEST CONTENT');
    }

    public function testCanEnqueueCallables()
    {
        $r = new Response();
        $fn = function ($req, $o) use ($r) { return $r; };
        $mock = new MockHandler([$fn]);
        $request = new Request('GET', 'http://example.com');
        $p = $mock($request, ['foo' => 'bar']);
        $this->assertSame($r, $p->wait());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresOnHeadersIsCallable()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $mock($request, ['on_headers' => 'error!']);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\RequestException
     * @expectedExceptionMessage An error was encountered during the on_headers event
     * @expectedExceptionMessage test
     */
    public function testRejectsPromiseWhenOnHeadersFails()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $promise = $mock($request, [
            'on_headers' => function () {
                throw new \Exception('test');
            }
        ]);

        $promise->wait();
    }
    public function testInvokesOnFulfilled()
    {
        $res = new Response();
        $mock = new MockHandler([$res], function ($v) use (&$c) {
            $c = $v;
        });
        $request = new Request('GET', 'http://example.com');
        $mock($request, [])->wait();
        $this->assertSame($res, $c);
    }

    public function testInvokesOnRejected()
    {
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, function ($v) use (&$c) { $c = $v; });
        $request = new Request('GET', 'http://example.com');
        $mock($request, [])->wait(false);
        $this->assertSame($e, $c);
    }

    /**
     * @expectedException \OutOfBoundsException
     */
    public function testThrowsWhenNoMoreResponses()
    {
        $mock = new MockHandler();
        $request = new Request('GET', 'http://example.com');
        $mock($request, []);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\BadResponseException
     */
    public function testCanCreateWithDefaultMiddleware()
    {
        $r = new Response(500);
        $mock = MockHandler::createWithMiddleware([$r]);
        $request = new Request('GET', 'http://example.com');
        $mock($request, ['http_errors' => true])->wait();
    }

    public function testInvokesOnStatsFunctionForResponse()
    {
        $res = new Response();
        $mock = new MockHandler([$res]);
        $request = new Request('GET', 'http://example.com');
        $stats = null;
        $onStats = function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $p = $mock($request, ['on_stats' => $onStats]);
        $p->wait();
        $this->assertSame($res, $stats->getResponse());
        $this->assertSame($request, $stats->getRequest());
    }

    public function testInvokesOnStatsFunctionForError()
    {
        $e = new \Exception('a');
        $c = null;
        $mock = new MockHandler([$e], null, function ($v) use (&$c) { $c = $v; });
        $request = new Request('GET', 'http://example.com');
        $stats = null;
        $onStats = function (TransferStats $s) use (&$stats) {
            $stats = $s;
        };
        $mock($request, ['on_stats' => $onStats])->wait(false);
        $this->assertSame($e, $stats->getHandlerErrorData());
        $this->assertNull($stats->getResponse());
        $this->assertSame($request, $stats->getRequest());
    }
}
