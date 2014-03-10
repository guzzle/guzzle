<?php

namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Subscriber\HttpError;
use GuzzleHttp\Adapter\Transaction;
use GuzzleHttp\Message\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Mock;

/**
 * @covers GuzzleHttp\Subscriber\HttpError
 */
class HttpErrorTest extends \PHPUnit_Framework_TestCase
{
    public function testIgnoreSuccessfulRequests()
    {
        $event = $this->getEvent();
        $event->intercept(new Response(200));
        (new HttpError())->onComplete($event);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ClientException
     */
    public function testThrowsClientExceptionOnFailure()
    {
        $event = $this->getEvent();
        $event->intercept(new Response(403));
        (new HttpError())->onComplete($event);
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ServerException
     */
    public function testThrowsServerExceptionOnFailure()
    {
        $event = $this->getEvent();
        $event->intercept(new Response(500));
        (new HttpError())->onComplete($event);
    }

    private function getEvent()
    {
        return new CompleteEvent(new Transaction(new Client(), new Request('PUT', '/')));
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ClientException
     */
    public function testFullTransaction()
    {
        $client = new Client();
        $client->getEmitter()->attach(new Mock([
            new Response(403)
        ]));
        $client->get('http://httpbin.org');
    }
}
