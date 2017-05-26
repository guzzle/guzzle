<?php
namespace GuzzleHttp\Tests\Subscriber;

use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Fixture;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Transaction;

/**
 * @covers GuzzleHttp\Subscriber\Fixture
 */
class FixtureTest extends \PHPUnit_Framework_TestCase
{
    /** @var string */
    protected $tmpPath;

    protected function setUp()
    {
        parent::setUp();

        $this->tmpPath = '/tmp/' . uniqid('guzzle_fixture', true);
    }

    public function testCreatesFixturesPath()
    {
        $f = new Fixture($this->tmpPath);

        $path = $this->readAttribute($f, 'path');
        $this->assertEquals($this->tmpPath, $path);
        $this->assertFileExists($path);
    }

    public function testDescribesSubscribedEvents()
    {
        $f = new Fixture($this->tmpPath);
        $this->assertInternalType('array', $f->getEvents());
    }

    public function testDoesNotGetResponseFromFixture()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $e = new BeforeEvent($t);
        $f = new Fixture($this->tmpPath);
        $f->onBefore($e);

        $this->assertFalse($e->isPropagationStopped());
    }

    public function testGetsResponseFromFixture()
    {
        $request = new Request('GET', '/');
        $response = new Response(200);
        $responseString = (string) $response;

        mkdir($this->tmpPath, 0777, true);
        $fileName = $this->tmpPath . '/' . md5((string) $request) . '.fixture';
        file_put_contents($fileName, $responseString);

        $t = new Transaction(new Client(), $request);
        $e = new BeforeEvent($t);
        $f = new Fixture($this->tmpPath);
        $f->onBefore($e);

        $this->assertTrue($e->isPropagationStopped());
        $this->assertEquals($response, $t->response);
        $this->assertEquals((string) $response, (string) $t->response);
    }

    public function testWritesResponseToFixture()
    {
        $request = new Request('GET', '/');
        $response = new Response(200);
        $fileName = $this->tmpPath . '/' . md5($request) . '.fixture';

        $t = new Transaction(new Client(), $request);
        $t->response = $response;
        $e = new CompleteEvent($t);
        $f = new Fixture($this->tmpPath);
        $f->onComplete($e);

        $this->assertFileExists($fileName);
        $this->assertEquals((string) $response, file_get_contents($fileName));
    }

    public function testDoesNotWriteFixtureWhenResponseIsMocked()
    {
        $client = new Client(['base_url' => 'http://test.com']);
        $mockedResponse = new Response(200);
        $mock = new Mock([$mockedResponse]);
        $f = new Fixture($this->tmpPath);
        $client->getEmitter()->attach($mock);
        $client->getEmitter()->attach($f);

        $request = $client->createRequest('GET', '/');
        $fileName = $this->tmpPath . '/' . md5($request) . '.fixture';

        $response = $client->send($request);

        $this->assertFileNotExists($fileName);
        $this->assertSame($response, $mockedResponse);
    }
}
 