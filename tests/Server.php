<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use Psr\Http\Message\ResponseInterface;

/**
 * The Server class is used to control a scripted webserver using node.js that
 * will respond to HTTP requests with queued responses.
 *
 * Queued responses will be served to requests using a FIFO order.  All requests
 * received by the server are stored on the node.js server and can be retrieved
 * by calling {@see Server::received()}.
 *
 * Mock responses that don't require data to be transmitted over HTTP a great
 * for testing.  Mock response, however, cannot test the actual sending of an
 * HTTP request using cURL.  This test server allows the simulation of any
 * number of HTTP request response transactions to test the actual sending of
 * requests over the wire without having to leave an internal network.
 */
class Server
{
    /** @var Client */
    private static $client;
    private static $started = false;
    public static $url = 'http://127.0.0.1:8126/';
    public static $port = 8126;

    /**
     * Flush the received requests from the server
     * @throws \RuntimeException
     */
    public static function flush()
    {
        return self::getClient()->request('DELETE', 'guzzle-server/requests');
    }

    /**
     * Queue an array of responses or a single response on the server.
     *
     * Any currently queued responses will be overwritten.  Subsequent requests
     * on the server will return queued responses in FIFO order.
     *
     * @param array|ResponseInterface $responses A single or array of Responses
     *                                           to queue.
     * @throws \Exception
     */
    public static function enqueue($responses)
    {
        $data = [];
        foreach ((array) $responses as $response) {
            if (!($response instanceof ResponseInterface)) {
                throw new \Exception('Invalid response given.');
            }
            $headers = array_map(function ($h) {
                return implode(' ,', $h);
            }, $response->getHeaders());

            $data[] = [
                'status'  => (string) $response->getStatusCode(),
                'reason'  => $response->getReasonPhrase(),
                'headers' => $headers,
                'body'    => base64_encode((string) $response->getBody())
            ];
        }

        self::getClient()->request('PUT', 'guzzle-server/responses', [
            'json' => $data
        ]);
    }

    /**
     * Get all of the received requests
     *
     * @return ResponseInterface[]
     * @throws \RuntimeException
     */
    public static function received()
    {
        if (!self::$started) {
            return [];
        }

        $response = self::getClient()->request('GET', 'guzzle-server/requests');
        $data = json_decode($response->getBody(), true);

        return array_map(
            function ($message) {
                $uri = $message['uri'];
                if (isset($message['query_string'])) {
                    $uri .= '?' . $message['query_string'];
                }
                $response = new Psr7\Request(
                    $message['http_method'],
                    $uri,
                    $message['headers'],
                    $message['body'],
                    $message['version']
                );
                return $response->withUri(
                    $response->getUri()
                        ->withScheme('http')
                        ->withHost($response->getHeaderLine('host'))
                );
            },
            $data
        );
    }

    /**
     * Stop running the node.js server
     */
    public static function stop()
    {
        if (self::$started) {
            self::getClient()->request('DELETE', 'guzzle-server');
        }

        self::$started = false;
    }

    public static function wait($maxTries = 5)
    {
        $tries = 0;
        while (!self::isListening() && ++$tries < $maxTries) {
            usleep(100000);
        }

        if (!self::isListening()) {
            throw new \RuntimeException('Unable to contact node.js server');
        }
    }

    public static function start()
    {
        if (self::$started) {
            return;
        }

        if (!self::isListening()) {
            exec('node ' . __DIR__ . '/server.js '
                . self::$port . ' >> /tmp/server.log 2>&1 &');
            self::wait();
        }

        self::$started = true;
    }

    private static function isListening()
    {
        try {
            self::getClient()->request('GET', 'guzzle-server/perf', [
                'connect_timeout' => 5,
                'timeout'         => 5
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function getClient()
    {
        if (!self::$client) {
            self::$client = new Client([
                'base_uri' => self::$url,
                'sync'     => true,
            ]);
        }

        return self::$client;
    }
}
