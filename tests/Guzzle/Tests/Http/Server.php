<?php

namespace Guzzle\Tests\Http;

use Guzzle\Http\HttpException;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;

/**
 * The Server class is used to control a scripted webserver using node.js that
 * will respond to HTTP requests with queued responses.
 * 
 * Queued responses will be served to requests using a FIFO order.  All requests
 * received by the server are stored on the node.js server and can be retreived
 * by calling {@see Server::getReceivedRequests()}.
 * 
 * Mock responses that don't require data to be transmitted over HTTP a great
 * for testing.  Mock respones, however, cannot test the actual sending of an
 * HTTP request using cURL.  This test server allows the simulation of any
 * number of HTTP request response transactions to test the actual sending of
 * requests over the wire without having to leave an internal network.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Server
{
    const DEFAULT_PORT = 8124;
    const REQUEST_DELIMITER = "\n----[request]\n";

    /**
     * @var int Port that the server will listen on
     */
    private $port;

    /**
     * @var bool Is the server running
     */
    private $running = false;

    /**
     * Create a new scripted server
     *
     * @param int $port (optional) Port to listen on (defaults to 8124)
     */
    public function __construct($port = null)
    {
        $this->port = $port ?: self::DEFAULT_PORT;
    }

    /**
     * Destructor to safely shutdown the node.js server if it is still running
     *
     * @codeCoverageIgnore
     */
    public function __destruct()
    {
        // Only shut the server down if the object knows it started the server
        if ($this->running) {
            try {
                $this->stop();
            } catch (\Exception $e) {
            }
        }
    }

    /**
     * Flush the received requests from the server
     *
     * @return bool Returns TRUE on success or FALSE on failure
     * @throws HttpException
     */
    public function flush()
    {
        if (!$this->isRunning()) {
            return false;
        }
        
        return RequestFactory::delete($this->getUrl() . 'guzzle-server/requests')
            ->send()->getStatusCode() == 200;
    }

    /**
     * Queue an array of responses or a single response on the server.
     *
     * Any currently queued responses will be overwritten.  Subsequent requests
     * on the server will return queued responses in FIFO order.
     *
     * @param array|Response $responses A single or array of Responses to queue
     *
     * @return bool Returns TRUE on success or FALSE on failure
     * @throws HttpException
     */
    public function enqueue($responses)
    {
        $data = array();
        foreach ((array) $responses as $response) {

            // Create the response object from a string
            if (is_string($response)) {
                $response = Response::factory($response);
            } else if (!($response instanceof Response)) {
                throw new HttpException(
                    'Responses must be strings or implement Response'
                );
            }

            $data[] = array(
                'statusCode' => $response->getStatusCode(),
                'reasonPhrase' => $response->getReasonPhrase(),
                'headers' => $response->getHeaders()->getAll(),
                'body' => $response->getBody(true)
            );
        }

        $response = RequestFactory::put($this->getUrl() . 'guzzle-server/responses', null, json_encode($data))
            ->send();

        return $response->getStatusCode() == 200;
    }

    /**
     * Check if the server is running
     *
     * @return bool
     */
    public function isRunning()
    {
        if ($this->running) {
            return true;
        } else {
            $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 1);
            if (!$fp) {
                return false;
            } else {
                fclose($fp);
                return true;
            }
        }
    }

    /**
     * Get the URL to the server
     *
     * @return string
     */
    public function getUrl()
    {
        return 'http://127.0.0.1:' . $this->getPort() . '/';
    }

    /**
     * Get the port that the server is listening on
     *
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * Get all of the received requests
     *
     * @param bool $hydrate (optional) Set to TRUE to turn the messages into
     *      actual {@see RequestInterface} objects.  If $hydrate is FALSE,
     *      requests will be returned as strings.
     *
     * @return array
     * @throws HttpException
     */
    public function getReceivedRequests($hydrate = false)
    {
        $data = array();

        if ($this->isRunning()) {
            $response = RequestFactory::get($this->getUrl() . 'guzzle-server/requests')->send();
            $data = array_filter(explode(self::REQUEST_DELIMITER, $response->getBody(true)));
            if ($hydrate) {
                $data = array_map(function($message) {
                    return RequestFactory::fromMessage($message);
                }, $data);
            }
        }

        return $data;
    }

    /**
     * Start running the node.js server in the background
     */
    public function start()
    {
        if (!$this->isRunning()) {
            exec('node ' . __DIR__ . \DIRECTORY_SEPARATOR . 'server.js ' . $this->port . ' >> /tmp/server.log 2>&1 &');
            // Shut the server down when the script exits unexpectedly
            register_shutdown_function(array($this, 'stop'));
            // Wait at most 5 seconds for the server the setup before proceeding
            $start = time();
            while (!$this->isRunning() && time() - $start < 5);
            // @codeCoverageIgnoreStart
            if (!$this->isRunning()) {
                throw new HttpException(
                    'Unable to contact server.js.  Have you installed node.js '
                    . 'v0.5.0+?  The node.js executable, node, must also be in '
                    . 'your path.'
                );
            }
            // @codeCoverageIgnoreEnd
        }

        $this->running = true;
    }

    /**
     * Stop running the node.js server
     *
     * @return bool Returns TRUE on success or FALSE on failure
     * @throws HttpException
     */
    public function stop()
    {
        if (!$this->isRunning()) {
            return false;
        }

        $this->running = false;
        
        return RequestFactory::delete($this->getUrl() . 'guzzle-server')->send()
            ->getStatusCode() == 200;
    }
}