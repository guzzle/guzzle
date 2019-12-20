<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Client;
use Http\Client\Tests\HttpClientTest;
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
