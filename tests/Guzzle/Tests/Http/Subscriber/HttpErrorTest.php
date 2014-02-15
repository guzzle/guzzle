<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Event\CompleteEvent;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Subscriber\HttpError;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Client;
use Guzzle\Http\Subscriber\Mock;

/**
 * @covers Guzzle\Http\Subscriber\HttpError
 */
class HttpErrorTest extends \PHPUnit_Framework_TestCase
{
    public function testIgnoreSuccessfulRequests()
    {
        $event = $this->getEvent();
        $event->intercept(new Response(200));
        (new HttpError())->onRequestAfterSend($event);
    }

    /**
     * @expectedException \Guzzle\Http\Exception\ClientException
     */
    public function testThrowsClientExceptionOnFailure()
    {
        $event = $this->getEvent();
        $event->intercept(new Response(403));
        (new HttpError())->onRequestAfterSend($event);
    }

    /**
     * @expectedException \Guzzle\Http\Exception\ServerException
     */
    public function testThrowsServerExceptionOnFailure()
    {
        $event = $this->getEvent();
        $event->intercept(new Response(500));
        (new HttpError())->onRequestAfterSend($event);
    }

    private function getEvent()
    {
        return new CompleteEvent(new Transaction(new Client(), new Request('PUT', '/')));
    }

    /**
     * @expectedException \Guzzle\Http\Exception\ClientException
     */
    public function testFullTransaction()
    {
        $client = new Client();
        $client->getEmitter()->addSubscriber(new Mock([
            new Response(403)
        ]));
        $client->get('/');
    }
}
