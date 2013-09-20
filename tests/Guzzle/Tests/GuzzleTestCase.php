<?php

namespace Guzzle\Tests;

use Guzzle\Http\Message\Response;
use Guzzle\Tests\Http\Server;

abstract class GuzzleTestCase extends \PHPUnit_Framework_TestCase
{
    public static $server;

    /**
     * Get the global server object used throughout the unit tests of Guzzle
     *
     * @return Server
     */
    public static function getServer()
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
}
