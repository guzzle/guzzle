<?php

namespace Guzzle\Tests\Service\Command;

use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;

abstract class AbstractCommandTest extends \Guzzle\Tests\GuzzleTestCase
{
    protected function getClient()
    {
        $service = ServiceDescription::factory(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'test_service.xml');
        $client =  new Client('http://www.google.com/');
        $client->setDescription($service);

        return $client;
    }
}
