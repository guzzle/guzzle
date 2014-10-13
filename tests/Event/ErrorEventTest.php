<?php
namespace GuzzleHttp\Tests\Event;

use GuzzleHttp\Transaction;
use GuzzleHttp\Client;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Message\Request;

/**
 * @covers GuzzleHttp\Event\ErrorEvent
 */
class ErrorEventTest extends \PHPUnit_Framework_TestCase
{
    public function testInterceptsWithEvent()
    {
        $t = new Transaction(new Client(), new Request('GET', '/'));
        $except = new RequestException('foo', $t->request);
        $t->exception = $except;
        $e = new ErrorEvent($t);
        $this->assertSame($e->getException(), $t->exception);
    }
}
