<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\MutableClient;

class MutableClientTest extends \PHPUnit_Framework_TestCase
{
    public function testCanChangeClientOptions()
    {
        $auth = ['username', 'password'];
        $client = new MutableClient(['auth' => $auth]);
        $this->assertEquals($client->getConfig('auth'), $auth);
        $newAuth = ['newsername', 'newword'];
        $client->setConfigOption('auth', $newAuth);
        $this->assertEquals($client->getConfig('auth'), $newAuth);
    }
}
