<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Server.php';

use GuzzleHttp\Tests\Server;

register_shutdown_function(function () {
    if (Server::$started) {
        Server::stop();
    }
});
