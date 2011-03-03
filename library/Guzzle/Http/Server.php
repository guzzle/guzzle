<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http;

use Guzzle\Common\Subject\AbstractSubject;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;

/**
 * Server is used to create a scripted webserver using node.js that will
 * respond to HTTP requests with queued responses
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Server extends AbstractSubject
{
    const DEFAULT_PORT = 8124;
    const RESPONSE_DELIMITER = "\n----[request]\n";

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
     * Destructor to cleanup the server
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
        
        return RequestFactory::getInstance()->newRequest('DELETE', $this->getUrl() . 'guzzle-server/requests')
            ->send()->getStatusCode() == 200;
    }

    /**
     * Queue an array of responses on the server
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

        $response = RequestFactory::getInstance()->newRequest(
            'PUT',
            $this->getUrl() . 'guzzle-server/responses',
            null,
            json_encode($data)
        )->send();

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
     *      actual RequestInterface objects
     *
     * @return array
     * @throws HttpException
     */
    public function getReceivedRequests($hydrate = false)
    {
        $data = array();

        if ($this->isRunning()) {
            $response = RequestFactory::getInstance()->newRequest('GET', $this->getUrl() . 'guzzle-server/requests')->send();
            $data = array_filter(explode(self::RESPONSE_DELIMITER, $response->getBody(true)));
            if ($hydrate) {
                $data = array_map(function($message) {
                    return RequestFactory::getInstance()->createFromMessage($message);
                }, $data);
            }
        }

        return $data;
    }

    /**
     * Start running the server
     */
    public function start()
    {
        if (!$this->isRunning()) {
            exec('node ' . __DIR__ . \DIRECTORY_SEPARATOR . 'server.js ' . $this->port . ' >> /tmp/server.log 2>&1 &');
            // Shut the server down when the script exits unexpectedly
            register_shutdown_function(array($this, 'stop'));
            // Wait for the server the setup before proceeding
            while (!$this->isRunning());
        }

        $this->running = true;
    }

    /**
     * Stop running the server
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
        
        return RequestFactory::getInstance()->newRequest('DELETE', $this->getUrl() . 'guzzle-server')->send()
            ->getStatusCode() == 200;
    }
}