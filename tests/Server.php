<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Message\MessageFactory;
use GuzzleHttp\Message\Response;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\RingBridge;
use GuzzleHttp\Tests\Ring\Client\Server as TestServer;

/**
 * Placeholder for the RingPHP-Client server that makes it easier to use.
 */
class Server
{
    public static $url = 'http://127.0.0.1:8125/';
    public static $port = 8125;

    /**
     * Queue an array of responses or a single response on the server.
     *
     * Any currently queued responses will be overwritten.  Subsequent requests
     * on the server will return queued responses in FIFO order.
     *
     * @param array $responses Responses to queue.
     * @throws \Exception
     */
    public static function enqueue(array $responses)
    {
        static $factory;
        if (!$factory) {
            $factory = new MessageFactory();
        }

        $data = [];
        foreach ($responses as $response) {
            // Create the response object from a string
            if (is_string($response)) {
                $response = $factory->fromMessage($response);
            } elseif (!($response instanceof ResponseInterface)) {
                throw new \Exception('Responses must be strings or Responses');
            }
            $data[] = self::convertResponse($response);
        }

        TestServer::enqueue($data);
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
        $response = TestServer::received();

        if ($hydrate) {
            $c = new Client();
            $factory = new MessageFactory();
            $response = array_map(function($message) use ($factory, $c) {
                return RingBridge::fromRingRequest($message);
            }, $response);
        }

        return $response;
    }

    public static function flush()
    {
        TestServer::flush();
    }

    public static function stop()
    {
        TestServer::stop();
    }

    public static function wait($maxTries = 5)
    {
        TestServer::wait($maxTries);
    }

    public static function start()
    {
        TestServer::start();
    }

    private static function convertResponse(Response $response)
    {
        $headers = array_map(function ($h) {
            return implode(', ', $h);
        }, $response->getHeaders());

        return [
            'status'  => $response->getStatusCode(),
            'reason'  => $response->getReasonPhrase(),
            'headers' => $headers,
            'body'    => base64_encode((string) $response->getBody())
        ];
    }
}
