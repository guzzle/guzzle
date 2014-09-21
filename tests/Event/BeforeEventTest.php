<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Message\Response;

/**
 * @covers GuzzleHttp\Event\BeforeEvent
 */
class BeforeEventTest extends \PHPUnit_Framework_TestCase
{
    public function testInterceptsWithEvent()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $t->exception = new \Exception('foo');
        $e = new BeforeEvent($t);
        $response = new Response(200);
        $e->intercept($response);
        $this->assertTrue($e->isPropagationStopped());
        $this->assertSame($t->response, $response);
        $this->assertNull($t->exception);
    }
}
