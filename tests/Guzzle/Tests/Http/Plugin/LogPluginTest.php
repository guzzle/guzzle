<?php

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Guzzle;
use Guzzle\Common\Log\ClosureLogAdapter;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Http\Plugin\LogPlugin;

/**
 * @group server
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class LogPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var LogPlugin
     */
    private $plugin;

    /**
     * @var ClosureLogAdapter
     */
    private $logAdapter;

    public function setUp()
    {
        $this->logAdapter = new ClosureLogAdapter(
            function($message, $priority, $extras = null) {
                echo $message . ' - ' . $priority . ' ' . implode(', ', (array) $extras) . "\n";
            }
        );

        $this->plugin = new LogPlugin($this->logAdapter);
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
     * @covers Guzzle\Http\Plugin\LogPlugin::getLogAdapter
     */
    public function testPluginHasLogAdapter()
    {
        $plugin = new LogPlugin($this->logAdapter);
        $this->assertEquals($this->logAdapter, $plugin->getLogAdapter());
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin::update
     * @covers Guzzle\Http\Plugin\LogPlugin::log
     */
    public function testLogsRequestAndResponseContext()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request = new Request('GET', $this->getServer()->getUrl());

        $plugin = new LogPlugin($this->logAdapter);
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
        $this->assertContains('7 guzzle.request', $message);
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin::update
     * @covers Guzzle\Http\Plugin\LogPlugin::log
     */
    public function testLogsRequestAndResponseWireHeaders()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\ndata");
        $request = new Request('GET', $this->getServer()->getUrl());
        $plugin = new LogPlugin($this->logAdapter, LogPlugin::LOG_CONTEXT | LogPlugin::LOG_HEADERS);
        $request->getEventManager()->attach($plugin);

        ob_start();
        $request->send();
        $message = ob_get_clean();

        // Make sure the context was logged
        $this->assertContains('127.0.0.1 - "GET / HTTP/1.1" - 200 4 - ', $message);
        $this->assertContains('7 guzzle.request', $message);

        // Check that the headers were logged
        $this->assertContains("GET / HTTP/1.1\r\n", $message);
        $this->assertContains("User-Agent: " . Guzzle::getDefaultUserAgent(), $message);
        $this->assertContains("Accept: */*\r\n", $message);
        $this->assertContains("Accept-Encoding: deflate, gzip", $message);
        $this->assertContains("Host: 127.0.0.1:", $message);

        // Make sure the response headers are present with a line between the request and response
        $this->assertContains("\n< HTTP/1.1 200 OK\r\n< Content-Length: 4", $message);
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin::update
     * @covers Guzzle\Http\Plugin\LogPlugin::log
     */
    public function testLogsRequestAndResponseWireContentAndHeaders()
    {
        $request = new EntityEnclosingRequest('PUT', $this->getServer()->getUrl());
        $request->setBody(EntityBody::factory('send'));
        $plugin = new LogPlugin($this->logAdapter, LogPlugin::LOG_CONTEXT | LogPlugin::LOG_HEADERS | LogPlugin::LOG_BODY);
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
        $this->assertContains("\n< HTTP/1.1 200 OK\r\n< Content-Length: 4", $message);

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
        $request = new EntityEnclosingRequest('PUT', $this->getServer()->getUrl());
        $plugin = new LogPlugin($this->logAdapter, LogPlugin::LOG_CONTEXT | LogPlugin::LOG_HEADERS | LogPlugin::LOG_BODY);
        $request->getEventManager()->attach($plugin);

        // Send the response from the dummy server as the request body
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nsend");
        $stream = fopen($this->getServer()->getUrl(), 'r');
        $request->setBody(EntityBody::factory($stream, 4));

        $tmpFile = tempnam('/tmp', 'testLogsRequestAndResponseWireContentAndHeadersNonStreamable');
        $request->setResponseBody(EntityBody::factory(fopen($tmpFile, 'w')));

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
        $this->assertContains("\n< HTTP/1.1 200 OK\r\n< Content-Length: 8", $message);
        // Response body
        $this->assertContains("\nresponse", $message);

        unlink($tmpFile);
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin
     */
    public function testLogsWhenExceptionsAreThrown()
    {
        $request = new Request('GET', $this->getServer()->getUrl());
        $plugin = new LogPlugin($this->logAdapter, LogPlugin::LOG_VERBOSE);
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
        $this->assertContains("\n< HTTP/1.1 404 Not Found\r\n< Content-Length: 0", $message);
    }

    /**
     * Data provider to test log verbosity
     *
     * @return array
     */
    public function verbosityProvider()
    {
        $u = str_replace('http://', '', substr($this->getServer()->getUrl(), 0, -1));

        return array(
            array(LogPlugin::LOG_CONTEXT, "GET /abc HTTP/1.1\r\nHost: $u\r\n\r\n"),
            array(LogPlugin::LOG_CONTEXT | LogPlugin::LOG_DEBUG, "GET /abc HTTP/1.1\r\nHost: $u\r\n\r\n"),
            array(LogPlugin::LOG_CONTEXT | LogPlugin::LOG_DEBUG | LogPlugin::LOG_HEADERS, "GET /abc HTTP/1.1\r\nHost: $u\r\n\r\n"),
            array(LogPlugin::LOG_CONTEXT | LogPlugin::LOG_DEBUG | LogPlugin::LOG_HEADERS | LogPlugin::LOG_BODY, "GET /abc HTTP/1.1\r\nHost: $u\r\n\r\n"),
            array(LogPlugin::LOG_VERBOSE, "GET /abc HTTP/1.1\r\nHost: $u\r\n\r\n"),
            array(LogPlugin::LOG_HEADERS, "GET /abc HTTP/1.1\r\nHost: $u\r\n\r\n"),
            array(LogPlugin::LOG_CONTEXT, "PUT /abc HTTP/1.1\r\nHost: $u\r\nContent-Length: 4\r\n\r\ndata"),
            array(LogPlugin::LOG_VERBOSE, "PUT /abc HTTP/1.1\r\nHost: $u\r\nContent-Length: 4\r\n\r\ndata"),
        );
    }

    /**
     * @covers Guzzle\Http\Plugin\LogPlugin
     * @dataProvider verbosityProvider
     */
    public function testLogsTransactionsAtDifferentLevels($level, $request)
    {
        $request = RequestFactory::fromMessage($request);
        $plugin = new LogPlugin(new ClosureLogAdapter(
            function($message, $priority, $extras = null) {
                echo $message . "\n";
            }
        ), $level);
        $request->getEventManager()->attach($plugin);
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nresp");

        ob_start();
        $request->send();
        $gen = ob_get_clean();
        
        $parts = explode("\n", trim($gen), 2);

        // Check if the context was properly logged
        if ($level & LogPlugin::LOG_CONTEXT) {
            $this->assertContains('127.0.0.1 - "' . $request->getMethod() . ' /', $gen);
        }
        
        // Check if the line count is 1 when just logging the context
        if ($level == LogPlugin::LOG_CONTEXT) {
            $this->assertEquals(1, count($parts));
            return;
        }

        // Check if debug information is being logged
        if ($level & LogPlugin::LOG_DEBUG) {
            $this->assertContains("\n* Connected to 127.0.0.1 (127.0.0.1) port", $gen);
        }

        // Check if the headers are being properly logged
        if ($level & LogPlugin::LOG_HEADERS) {
            $this->assertContains("> " . $request->getMethod() . " /abc HTTP/1.1", $gen);
            // Ensure headers following the request line are logged
            $this->assertContains("Accept: */*", $gen);
            // Ensure the response headers are being logged
            $this->assertContains("< HTTP/1.1 200 OK\r\n< Content-Length: 4", $gen);
        }

        // Check if the body of the request and response are being logged
        if ($level & LogPlugin::LOG_BODY) {
            if ($request instanceof EntityEnclosingRequest) {
                $this->assertContains("\r\n\r\n" . $request->getBody(true), $gen);
            }
            $this->assertContains("\nresp", $gen);
        }
    }
}