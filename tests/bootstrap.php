<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/guzzlehttp/ring-client/tests/Server.php';

use GuzzleHttp\Tests\Server;

Server::start();

register_shutdown_function(function () {
    Server::stop();
});
