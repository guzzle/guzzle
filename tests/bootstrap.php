<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/guzzlehttp/ringphp/tests/Client/Server.php';

use GuzzleHttp\Tests\Server;

Server::start();

register_shutdown_function(function () {
    Server::stop();
});
