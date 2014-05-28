<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Client;

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
    const REQUEST_DELIMITER = "\n----[request]\n";

    /** @var Client */
    private static $client;

    public static $started;
    public static $url = 'http://127.0.0.1:8124/';
    public static $port = 8124;

    /**
     * Flush the received requests from the server
     * @throws \RuntimeException
     */
    public static function flush()
    {
        self::start();

        return self::$client->delete('guzzle-server/requests');
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
        static $factory;
        if (!$factory) {
            $factory = new MessageFactory();
        }

        self::start();

        $data = [];
        foreach ((array) $responses as $response) {

            // Create the response object from a string
            if (is_string($response)) {
                $response = $factory->fromMessage($response);
            } elseif (!($response instanceof ResponseInterface)) {
                throw new \Exception('Responses must be strings or Responses');
            }

            $headers = array_map(function ($h) {
                return implode(' ,', $h);
            }, $response->getHeaders());

            $data[] = [
                'statusCode'   => $response->getStatusCode(),
                'reasonPhrase' => $response->getReasonPhrase(),
                'headers'      => $headers,
                'body'         => (string) $response->getBody()
            ];
        }

        self::getClient()->put('guzzle-server/responses', [
            'body' => json_encode($data)
        ]);
    }

    /**
     * Get all of the received requests
     *
     * @param bool $hydrate Set to TRUE to turn the messages into
     *      actual {@see RequestInterface} objects.  If $hydrate is FALSE,
     *      requests will be returned as strings.
     *
     * @return array
     * @throws \RuntimeException
     */
    public static function received($hydrate = false)
    {
        if (!self::$started) {
            return [];
        }

        $response = self::getClient()->get('guzzle-server/requests');
        $data = array_filter(explode(self::REQUEST_DELIMITER, (string) $response->getBody()));
        if ($hydrate) {
            $factory = new MessageFactory();
            $data = array_map(function($message) use ($factory) {
                return $factory->fromMessage($message);
            }, $data);
        }

        return $data;
    }

    /**
     * Stop running the node.js server
     */
    public static function stop()
    {
        if (self::$started) {
            self::getClient()->delete('guzzle-server');
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

    private static function start()
    {
        if (self::$started){
            return;
        }

        if (!self::isListening()) {
            exec('node ' . __DIR__ . \DIRECTORY_SEPARATOR . 'server.js '
                . self::$port . ' >> /tmp/server.log 2>&1 &');
            self::wait();
        }

        self::$started = true;
    }

    private static function isListening()
    {
        try {
            self::getClient()->get('guzzle-server/perf', [
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
            self::$client = new Client(['base_url' => self::$url]);
        }

        return self::$client;
    }
}
