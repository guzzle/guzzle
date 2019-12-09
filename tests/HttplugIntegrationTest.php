<?php

namespace GuzzleHttp\Tests;

use Buzz\Client\Curl;
use GuzzleHttp\Client;
use Http\Client\Tests\HttpClientTest;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class HttplugIntegrationTest extends HttpClientTest
{
    protected function createHttpAdapter(): ClientInterface
    {
        return new Client();
    }
}
