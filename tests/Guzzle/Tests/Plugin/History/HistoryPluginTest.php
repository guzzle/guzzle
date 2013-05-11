<?php

namespace Guzzle\Tests\Plugin\History;

use Guzzle\Http\Client;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Plugin\History\HistoryPlugin;
use Guzzle\Plugin\Mock\MockPlugin;

/**
 * @covers Guzzle\Plugin\History\HistoryPlugin
 */
class HistoryPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * Adds multiple requests to a plugin
     *
     * @param HistoryPlugin $h Plugin
     * @param int $num Number of requests to add
     *
     * @return array
     */
    protected function addRequests(HistoryPlugin $h, $num)
    {
        $requests = array();
        $client = new Client('http://localhost/');
        for ($i = 0; $i < $num; $i++) {
            $requests[$i] = $client->get();
            $requests[$i]->setResponse(new Response(200), true);
            $requests[$i]->send();
            $h->add($requests[$i]);
        }

        return $requests;
    }

    public function testDescribesSubscribedEvents()
    {
        $this->assertInternalType('array', HistoryPlugin::getSubscribedEvents());
    }

    public function testMaintainsLimitValue()
    {
        $h = new HistoryPlugin();
        $this->assertSame($h, $h->setLimit(10));
        $this->assertEquals(10, $h->getLimit());
    }

    public function testAddsRequests()
    {
        $h = new HistoryPlugin();
        $requests = $this->addRequests($h, 1);
        $this->assertEquals(1, count($h));
        $i = $h->getIterator();
        $this->assertEquals(1, count($i));
        $this->assertEquals($requests[0], $i[0]);
    }

    /**
     * @depends testAddsRequests
     */
    public function testMaintainsLimit()
    {
        $h = new HistoryPlugin();
        $h->setLimit(2);
        $requests = $this->addRequests($h, 3);
        $this->assertEquals(2, count($h));
        $i = 0;
        foreach ($h as $request) {
            if ($i > 0) {
                $this->assertSame($requests[$i], $request);
            }
        }
    }

    public function testReturnsLastRequest()
    {
        $h = new HistoryPlugin();
        $requests = $this->addRequests($h, 5);
        $this->assertSame(end($requests), $h->getLastRequest());
    }

    public function testReturnsLastResponse()
    {
        $h = new HistoryPlugin();
        $requests = $this->addRequests($h, 5);
        $this->assertSame(end($requests)->getResponse(), $h->getLastResponse());
    }

    public function testClearsHistory()
    {
        $h = new HistoryPlugin();
        $requests = $this->addRequests($h, 5);
        $this->assertEquals(5, count($h));
        $h->clear();
        $this->assertEquals(0, count($h));
    }

    /**
     * @depends testAddsRequests
     */
    public function testUpdatesAddRequests()
    {
        $h = new HistoryPlugin();
        $client = new Client('http://localhost/');
        $client->getEventDispatcher()->addSubscriber($h);

        $request = $client->get();
        $request->setResponse(new Response(200), true);
        $request->send();

        $this->assertSame($request, $h->getLastRequest());
    }

    public function testCanCastToString()
    {
        $client = new Client('http://localhost/');
        $h = new HistoryPlugin();
        $client->getEventDispatcher()->addSubscriber($h);

        $mock = new MockPlugin(array(
            new Response(301, array('Location' => '/redirect1')),
            new Response(307, array('Location' => '/redirect2')),
            new Response(200, array('Content-Length' => '2'), 'HI')
        ));

        $client->getEventDispatcher()->addSubscriber($mock);
        $request = $client->get();
        $request->send();
        $this->assertEquals(3, count($h));
        $this->assertEquals(3, count($mock->getReceivedRequests()));

        $this->assertEquals(<<<EOT
> GET / HTTP/1.1
Host: localhost
User-Agent:

< HTTP/1.1 301 Moved Permanently
Location: /redirect1

> GET /redirect1 HTTP/1.1
Host: localhost
User-Agent:

< HTTP/1.1 307 Temporary Redirect
Location: /redirect2

> GET /redirect2 HTTP/1.1
Host: localhost
User-Agent:

< HTTP/1.1 200 OK
Content-Length: 2
EOT
, preg_replace('/User\-Agent: .*/', 'User-Agent:', str_replace("\r", '', trim((string) $h))));
    }
}
