<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http;

use Guzzle\Common\Event\EventManager;
use Guzzle\Common\Event\Observer;
use Guzzle\Http\Server;
use Guzzle\Http\Message\BadResponseException;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Server\Action\ResponseAction;

/**
 * Scripted server test case.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ServerTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * Remove node.js generated keep-alive header
     *
     * @param string $response Response
     *
     * @return string
     */
    protected function removeKeepAlive($response)
    {
        return str_replace("Connection: keep-alive\r\n", '', $response);
    }

    /**
     * @covers Guzzle\Http\Server::__construct
     * @covers Guzzle\Http\Server::getPort
     * @covers Guzzle\Http\Server::getUrl
     */
    public function testConstructorSetsPort()
    {
        $server = new Server();
        $this->assertNotNull($server->getPort());
        $this->assertEquals('http://127.0.0.1:' . $server->getPort() . '/', $server->getUrl());
    }

    /**
     * @covers Guzzle\Http\Server::enqueue
     */
    public function testEnqueuesResponses()
    {
        $server = $this->getServer();
        $this->assertTrue($server->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"));
        $r = new Request('GET', $server->getUrl() . 'guzzle-server/requests');
    }

    /**
     * @covers Guzzle\Http\Server
     */
    public function testServerReceivesRequests()
    {
        $server = $this->getServer();
        $this->assertTrue($server->flush());
        $server->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $factory = new RequestFactory();

        $request = $factory->newRequest('GET', $server->getUrl());
        $response = $request->send();
        $this->assertEquals(
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            $this->removeKeepAlive((string) $response)
        );

        $this->assertEquals(1, count($server->getReceivedRequests(false)));
        $requests = $server->getReceivedRequests(true);
        $req = $requests[0];
        $this->assertEquals('GET', $req->getMethod());
        $this->assertEquals($request->getUrl(), $req->getUrl());

        $this->assertTrue($server->flush());
        $this->assertEquals(0, count($server->getReceivedRequests(false)));
    }

    /**
     * @covers Guzzle\Http\Server::isRunning
     * @depends testServerReceivesRequests
     */
    public function testChecksIfAnotherServerIsAlreadyRunning()
    {
        $server = new Server();
        $this->assertTrue($server->isRunning());
    }

    /**
     * @covers Guzzle\Http\Server::enqueue
     * @expectedException Guzzle\Http\HttpException
     * @expectedExceptionMessage Responses must be strings or implement Response
     */
    public function testValidatesEnqueuedResponses()
    {
        $this->getServer()->enqueue(false);
    }

    /**
     * @covers Guzzle\Http\Server::start
     * @covers Guzzle\Http\Server::isRunning
     * @covers Guzzle\Http\Server::stop
     * @covers Guzzle\Http\Server::getPort
     * @covers Guzzle\Http\Server::flush
     */
    public function testStartsAndStopsListening()
    {
        $server = new Server(8123);
        $this->assertEquals(8123, $server->getPort());
        $this->assertFalse($server->isRunning());
        $this->assertFalse($server->flush());
        $server->start();
        $this->assertTrue($server->isRunning());
        try {
            RequestFactory::getInstance()->newRequest('GET', $server->getUrl())->send();
            $this->fail('Server must return 500 error when no responses are queued');
        } catch (BadResponseException $e) {
            $this->assertEquals(500, $e->getResponse()->getStatusCode());
        }
        $this->assertTrue($server->stop());
        $this->assertFalse($server->isRunning());
        $this->assertFalse($server->stop());
    }
}