<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Guzzle;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Plugin\HistoryPlugin;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
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
        for ($i = 0; $i < $num; $i++) {
            $requests[$i] = RequestFactory::get('http://localhost/');
            $requests[$i]->setResponse(new Response(200), true);
            $requests[$i]->send();
            $h->add($requests[$i]);
        }

        return $requests;
    }

    /**
     * @covers Guzzle\Http\Plugin\HistoryPlugin::getLimit
     * @covers Guzzle\Http\Plugin\HistoryPlugin::setLimit
     */
    public function testMaintainsLimitValue()
    {
        $h = new HistoryPlugin();
        $this->assertSame($h, $h->setLimit(10));
        $this->assertEquals(10, $h->getLimit());
    }

    /**
     * @covers Guzzle\Http\Plugin\HistoryPlugin::add
     * @covers Guzzle\Http\Plugin\HistoryPlugin::count
     * @covers Guzzle\Http\Plugin\HistoryPlugin::getIterator
     */
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
     * @covers Guzzle\Http\Plugin\HistoryPlugin::add
     */
    public function testIgnoresUnsentRequests()
    {
        $h = new HistoryPlugin();
        $request = RequestFactory::get('http://localhost/');
        $h->add($request);
        $this->assertEquals(0, count($h));
    }

    /**
     * @covers Guzzle\Http\Plugin\HistoryPlugin::add
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

    /**
     * @covers Guzzle\Http\Plugin\HistoryPlugin::getLastRequest
     */
    public function testReturnsLastRequest()
    {
        $h = new HistoryPlugin();
        $requests = $this->addRequests($h, 5);
        $this->assertSame(end($requests), $h->getLastRequest());
    }

    /**
     * @covers Guzzle\Http\Plugin\HistoryPlugin::getLastResponse
     */
    public function testReturnsLastResponse()
    {
        $h = new HistoryPlugin();
        $requests = $this->addRequests($h, 5);
        $this->assertSame(end($requests)->getResponse(), $h->getLastResponse());
    }

    /**
     * @covers Guzzle\Http\Plugin\HistoryPlugin::clear
     */
    public function testClearsHistory()
    {
        $h = new HistoryPlugin();
        $requests = $this->addRequests($h, 5);
        $this->assertEquals(5, count($h));
        $h->clear();
        $this->assertEquals(0, count($h));
    }

    /**
     * @covers Guzzle\Http\Plugin\HistoryPlugin::update
     * @depends testAddsRequests
     */
    public function testUpdatesAddRequests()
    {
        $h = new HistoryPlugin();
        $request = RequestFactory::get('http://localhost/');
        $request->setResponse(new Response(200), true);
        $request->getEventManager()->attach($h);
        $request->send();
        $this->assertSame($request, $h->getLastRequest());
    }
}