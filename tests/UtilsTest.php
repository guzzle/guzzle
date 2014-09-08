<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\Mock;
use GuzzleHttp\Utils;

class UtilsTest extends \PHPUnit_Framework_TestCase
{
    public function testExpandsTemplate()
    {
        $this->assertEquals(
            'foo/123',
            Utils::uriTemplate('foo/{bar}', ['bar' => '123'])
        );
    }

    public function noBodyProvider()
    {
        return [['get'], ['head'], ['delete']];
    }

    public function testBatchesRequests()
    {
        $client = new Client();
        $responses = [
            new Response(301, ['Location' => 'http://foo.com/bar']),
            new Response(200),
            new Response(200),
            new Response(404)
        ];
        $client->getEmitter()->attach(new Mock($responses));
        $requests = [
            $client->createRequest('GET', 'http://foo.com/baz'),
            $client->createRequest('HEAD', 'http://httpbin.org/get'),
            $client->createRequest('PUT', 'http://httpbin.org/put'),
        ];

        $a = $b = $c = 0;
        $result = Utils::batch($client, $requests, [
            'before'   => function (BeforeEvent $e) use (&$a) { $a++; },
            'complete' => function (CompleteEvent $e) use (&$b) { $b++; },
            'error'    => function (ErrorEvent $e) use (&$c) { $c++; },
        ]);

        $this->assertEquals(4, $a);
        $this->assertEquals(2, $b);
        $this->assertEquals(1, $c);
        $this->assertCount(3, $result);

        // The first result is actually the second (redirect) response.
        $this->assertSame($responses[1], $result[0]);
        // The second result is a 1:1 request:response map
        $this->assertSame($responses[2], $result[1]);
        // The third entry is the 404 RequestException
        $this->assertSame($responses[3], $result[2]->getResponse());
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid event format
     */
    public function testBatchValidatesTheEventFormat()
    {
        $client = new Client();
        $requests = [$client->createRequest('GET', 'http://foo.com/baz')];
        Utils::batch($client, $requests, ['complete' => 'foo']);
    }

    public function testJsonDecodes()
    {
        $this->assertTrue(Utils::jsonDecode('true'));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Unable to parse JSON data: JSON_ERROR_SYNTAX - Syntax error, malformed JSON
     */
    public function testJsonDecodesWithErrorMessages()
    {
        Utils::jsonDecode('!narf!');
    }
}
