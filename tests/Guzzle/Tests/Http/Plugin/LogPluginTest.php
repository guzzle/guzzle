<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Guzzle;
use Guzzle\Common\Log\Logger;
use Guzzle\Common\Log\Adapter\ClosureLogAdapter;
use Guzzle\Http\Plugin\LogPlugin;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class LogPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var LogPlugin
     */
    private $plugin;

    /**
     * @var Logger
     */
    private $logger;

    public function setUp()
    {
        $this->logger = new Logger(array(new ClosureLogAdapter(
            function($message, $priority, $category, $host) {
                echo $message . ' - ' . $priority . ' ' . $category . ' ' . $host . "\n";
            }
        )));

        $this->plugin = new LogPlugin($this->logger);
    }

    /**
     * Parse a log message into parts
     *
     * @param string $message Message to parse
     *
     * @return array
     */
    private function parseMessage($message)
    {
        $p = explode(' - ', $message, 4);
        
        $parts['host'] = trim($p[0]);
        $parts['request'] = str_replace('"', '', $p[1]);
        list($parts['code'], $parts['size']) = explode(' ', $p[2]);
        list($parts['time'], $parts['up'], $parts['down']) = explode(' ', $p[3]);
        $parts['extra'] = isset($p[4]) ? $p[4] : null;

        return $parts;
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin::__construct
     * @covers Guzzle\Http\Plugin\LogPlugin::getLogger
     */
    public function testHasLogger()
    {
        $plugin = new LogPlugin($this->logger);
        $this->assertEquals($this->logger, $plugin->getLogger());
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin::update
     * @covers Guzzle\Http\Plugin\LogPlugin::log
     */
    public function testLogsRequestAndResponseContext()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request = new \Guzzle\Http\Message\Request('GET', $this->getServer()->getUrl());

        $plugin = new LogPlugin($this->logger);
        $request->getEventManager()->attach($plugin);

        ob_start();
        $request->send();
        $message = ob_get_clean();
        $parts = $this->parseMessage($message);

        $this->assertEquals('127.0.0.1', $parts['host']);
        $this->assertEquals('GET / HTTP/1.1', $parts['request']);
        $this->assertEquals(200, $parts['code']);
        $this->assertEquals(0, $parts['size']);

        $this->assertContains('127.0.0.1 - "GET / HTTP/1.1" - 200 0 - ', $message);
        $this->assertContains('7 guzzle_request ' . gethostname(), $message);
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin::update
     * @covers Guzzle\Http\Plugin\LogPlugin::log
     */
    public function testLogsRequestAndResponseWireHeaders()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");
        $request = new \Guzzle\Http\Message\Request('GET', $this->getServer()->getUrl());
        $plugin = new LogPlugin($this->logger, true, LogPlugin::WIRE_HEADERS);
        $request->getEventManager()->attach($plugin);

        ob_start();
        $request->send();
        $message = ob_get_clean();

        // Make sure the context was logged
        $this->assertContains('127.0.0.1 - "GET / HTTP/1.1" - 200 4 - ', $message);
        $this->assertContains('7 guzzle_request ' . gethostname(), $message);

        // Check that the headers were logged
        $this->assertContains("GET / HTTP/1.1\r\n", $message);
        $this->assertContains("User-Agent: " . Guzzle::getDefaultUserAgent(), $message);
        $this->assertContains("Accept: */*\r\n", $message);
        $this->assertContains("Accept-Encoding: deflate, gzip", $message);
        $this->assertContains("Host: 127.0.0.1:", $message);

        // Make sure the response headers are present with a line between the request and response
        $this->assertContains("\n\nHTTP/1.1 200 OK\r\nContent-Length: 4", $message);
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin::update
     * @covers Guzzle\Http\Plugin\LogPlugin::log
     */
    public function testLogsRequestAndResponseWireContentAndHeaders()
    {
        $request = new \Guzzle\Http\Message\EntityEnclosingRequest('PUT', $this->getServer()->getUrl());
        $request->setBody(\Guzzle\Http\EntityBody::factory('send'));
        $plugin = new LogPlugin($this->logger, true, LogPlugin::WIRE_FULL);
        $request->getEventManager()->attach($plugin);

        ob_start();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");
        $request->send();
        $message = ob_get_clean();

        // Make sure the context was logged
        $this->assertContains('127.0.0.1 - "PUT / HTTP/1.1" - 200 4 - ', $message);

        // Check that the headers were logged
        $this->assertContains("PUT / HTTP/1.1\r\n", $message);
        $this->assertContains("User-Agent: " . Guzzle::getDefaultUserAgent(), $message);
        $this->assertContains("Content-Length: 4", $message);

        // Make sure the response headers are present with a line between the request and response
        $this->assertContains("\n\nHTTP/1.1 200 OK\r\nContent-Length: 4", $message);

        // Request payload
        $this->assertContains("\r\nsend", $message);

        // Response body
        $this->assertContains("data", $message);
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin
     */
    public function testLogsRequestAndResponseWireContentAndHeadersNonStreamable()
    {
        $request = new \Guzzle\Http\Message\EntityEnclosingRequest('PUT', $this->getServer()->getUrl());
        $plugin = new LogPlugin($this->logger, true, LogPlugin::WIRE_FULL);
        $request->getEventManager()->attach($plugin);

        // Send the response from the dummy server as the request body
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nsend");
        $stream = fopen($this->getServer()->getUrl(), 'r');
        $request->setBody(\Guzzle\Http\EntityBody::factory($stream, 4));

        $tmpFile = tempnam('/tmp', 'testLogsRequestAndResponseWireContentAndHeadersNonStreamable');
        $request->setResponseBody(\Guzzle\Http\EntityBody::factory(fopen($tmpFile, 'w')));

        ob_start();
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 8\r\n\r\nresponse");
        $request->send();
        $message = ob_get_clean();

        // Make sure the context was logged
        $this->assertContains('127.0.0.1 - "PUT / HTTP/1.1" - 200 8 - ', $message);

        // Check that the headers were logged
        $this->assertContains("PUT / HTTP/1.1\r\n", $message);
        $this->assertContains("User-Agent: " . Guzzle::getDefaultUserAgent(), $message);
        $this->assertContains("Content-Length: 4", $message);
        // Request payload
        $this->assertContains("\r\nsend", $message);

        // Make sure the response headers are present with a line between the request and response
        $this->assertContains("\n\nHTTP/1.1 200 OK\r\nContent-Length: 8", $message);
        // Response body
        $this->assertContains("\r\nresponse", $message);

        unlink($tmpFile);
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin
     */
    public function testLogsWhenExceptionsAreThrown()
    {
        $request = new \Guzzle\Http\Message\Request('GET', $this->getServer()->getUrl());
        $plugin = new LogPlugin($this->logger, true, LogPlugin::WIRE_FULL);
        $request->getEventManager()->attach($plugin);

        $this->getServer()->enqueue("HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n");

        ob_start();

        try {
            $request->send();
            $this->fail('Exception for 404 was not thrown');
        } catch (\Exception $e) {}

        $message = ob_get_clean();

        $this->assertContains('127.0.0.1 - "GET / HTTP/1.1" - 404 0 - ', $message);
        $this->assertContains("GET / HTTP/1.1\r\n", $message);
        $this->assertContains("User-Agent: " . Guzzle::getDefaultUserAgent(), $message);
        $this->assertContains("\n\nHTTP/1.1 404 Not Found\r\nContent-Length: 0", $message);

        // make sure the extra data was logged
        $this->assertContains("\nUnsuccessful response | [status code] 404 | [reason phrase] Not Found | [url] http://127.0.0.1:", $message);
    }
}