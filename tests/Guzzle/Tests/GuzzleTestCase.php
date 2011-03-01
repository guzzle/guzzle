<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests;

use Guzzle\Common\Log\Adapter\ZendLogAdapter;
use Guzzle\Common\Log\Logger;
use Guzzle\Http\Server;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Plugin\Log\LogPlugin;
use Guzzle\Service\Client;
use Guzzle\Service\Builder\ServiceBuilder;
use Guzzle\Tests\Common\Mock\MockFilter;

/**
 * Base testcase class for all Guzzle testcases.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class GuzzleTestCase extends \PHPUnit_Framework_TestCase
{
    private $requests = array();

    public static $serviceBuilder;
    public static $server;

    /**
     * Get the global server object used throughout the unit tests of Guzzle
     *
     * @return Server
     */
    public function getServer()
    {
        if (!self::$server) {
            self::$server = new Server();
            if (self::$server->isRunning()) {
                self::$server->flush();
            } else {
                self::$server->start();
            }
        }

        return self::$server;
    }

    /**
     * Get the services.xml location and filename
     *
     * @return string
     */
    public function getServicesFile()
    {
        return isset($_SERVER['GUZZLE_SERVICE_FILE'])
            ? $_SERVER['GUZZLE_SERVICE_FILE']
            : __DIR__ . DIRECTORY_SEPARATOR . 'services.xml';
    }

    /**
     * Get a service builder object that can be used throughout the service tests
     *
     * @return ServiceBuilder
     */
    public function getServiceBuilder()
    {
        if (!self::$serviceBuilder) {
            self::$serviceBuilder = ServiceBuilder::factory($this->getServicesFile());
        }

        return self::$serviceBuilder;
    }

    /**
     * Mark a request as being mocked
     *
     * @param RequestInterface $request
     */
    public function addMockedRequest(RequestInterface $request)
    {
        $this->requests[] = $request;

        return $this;
    }

    /**
     * Get all of the mocked requests
     *
     * @return array
     */
    public function getMockedRequests()
    {
        return $this->requests;
    }

    /**
     * Get a mock response for a client by mock file name
     *
     * @param Client $client Client object to modify
     * @param string $filename Name of the file within the Mock folder of the service
     *
     * @return Response
     */
    public function getMockResponse(Client $client, $filename)
    {
        
        $reflection = new \ReflectionClass(get_class($client));
        $path = str_replace(array(
            str_replace($reflection->getNamespaceName() . '\\', '', $reflection->getName()),
            '.php'
        ), '', $reflection->getFileName());

        // Handle nested mock files
        if (isset($_SERVER['GUZZLE_SERVICE_MULTI']) && (int) $_SERVER['GUZZLE_SERVICE_MULTI'] == 1) {
            $parts = explode(DIRECTORY_SEPARATOR, $path);
            $path = implode(DIRECTORY_SEPARATOR, array_slice($parts, 0, count($parts) - 2))
                . DIRECTORY_SEPARATOR . 'Tests'
                . DIRECTORY_SEPARATOR . $parts[count($parts) - 2]
                . DIRECTORY_SEPARATOR . 'Command'
                . DIRECTORY_SEPARATOR . 'Mock'
                . DIRECTORY_SEPARATOR . $filename;
        } else {
            // Create the path to the file
            $path .= DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR . 'Command' . DIRECTORY_SEPARATOR . 'Mock' . DIRECTORY_SEPARATOR . $filename;
        }

        if (!file_exists($path)) {
            throw new \Exception('Unable to open mock file: ' . $path);
        }

        $data = file_get_contents($path);
        $parts = explode("\n\n", $data, 2);

        // Convert \n to \r\n in headers
        if (!isset($parts[1])) {
            $data = $parts[0];
        } else {
            $data = str_replace("\n", "\r\n", $parts[0]) . "\r\n\r\n" . $parts[1];
        }

        // Create a response from the mock file
        return Response::factory($data);
    }

    /**
     * Set a mock response from a mock file on the next client request.
     *
     * This method assumes that mock response files are located under the
     * Command/Mock/ directory of the Service being tested
     * (e.g. Unfuddle/Command/Mock/).  A mock response is added to the next
     * request sent by the client.
     *
     * @param Client $client Client object to modify
     * @param string $filenames Name of the file within the Mock folder of the service
     */
    public function setMockResponse(Client $client, $filenames)
    {
        $this->requests = array();
        $responses = array();
        foreach ((array)$filenames as $filename) {
            $responses[] = $this->getMockResponse($client, $filename);
        }

        // Add a filter to the client to set a mock response on the next request
        $that = $this;
        $client->getCreateRequestChain()->addFilter(new MockFilter(array(
            'callback' => function($filter, $command) use (&$responses, $client, $that) {
                $that->addMockedRequest($command);
                // Set the mock response
                $command->setResponse(array_shift($responses), true);
                // Detach the filter from the client so it's a one-time use
                if (count($responses) == 0) {
                    $client->getCreateRequestChain()->removeFilter($filter);
                }
            }
        )));
    }

    /**
     * Enable debug mode on a client by outputting the request and response
     *
     * @param Client $client Client to debug
     *
     * @return Client
     */
    public function enableClientDebug(Client $client)
    {
        $adapter = new ZendLogAdapter(new \Zend_Log(new \Zend_Log_Writer_Stream('php://output')));
        $logger = new Logger(array($adapter));
        $plugin = new LogPlugin($logger, true, 2);
        $client->attachPlugin($plugin);
    }

    /**
     * Check if an array of HTTP headers matches another array of HTTP headers
     * while taking * into account as a wildcard for header values
     *
     * @param array $actual Actual HTTP header array
     * @param array $expected Expected HTTP headers (allows wildcard values)
     *
     * @return array|false Returns an array of the differences or FALSE if none
     */
    public function compareHttpHeaders(array $expected, array $actual)
    {
        $differences = array();

        foreach ($actual as $key => $value) {
            if (!isset($expected[$key]) || ($expected[$key] != '*' && $actual[$key] != $expected[$key])) {
                $differences[$key] = $value;
            }
        }

        return empty($differences) ? false : $differences;
    }
}