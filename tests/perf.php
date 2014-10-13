<?php
/*
 * Runs a performance test against the node.js server for both serial and
 * parallel requests. Requires PHP 5.5 or greater.
 *
 *     # Basic usage
 *     make perf
 *     # With custom options
 *     REQUESTS=100 PARALLEL=5000 make perf
 */

require __DIR__ . '/bootstrap.php';

use GuzzleHttp\Client;
use GuzzleHttp\Tests\Server;
use GuzzleHttp\Ring\Client\CurlMultiHandler;
use GuzzleHttp\Pool;

// Wait until the server is responding
Server::wait();

// Get custom make variables
$total = isset($_SERVER['REQUESTS']) ? $_SERVER['REQUESTS'] : 1000;
$parallel = isset($_SERVER['PARALLEL']) ? $_SERVER['PARALLEL'] : 100;

$client = new Client(['base_url' => Server::$url]);

$t = microtime(true);
for ($i = 0; $i < $total; $i++) {
    $client->get('/guzzle-server/perf');
}
$totalTime = microtime(true) - $t;
$perRequest = ($totalTime / $total) * 1000;
printf("Serial: %f (%f ms / request) %d total\n",
    $totalTime, $perRequest, $total);

// Create a generator used to yield batches of requests
$reqs = function () use ($client, $total) {
    for ($i = 0; $i < $total; $i++) {
        yield $client->createRequest('GET', '/guzzle-server/perf');
    }
};

$t = microtime(true);
Pool::send($client, $reqs(), ['parallel' => $parallel]);
$totalTime = microtime(true) - $t;
$perRequest = ($totalTime / $total) * 1000;
printf("Batch:  %f (%f ms / request) %d total with %d in parallel\n",
    $totalTime, $perRequest, $total, $parallel);

$handler = new CurlMultiHandler(['max_handles' => $parallel]);
$client = new Client(['handler' => $handler, 'base_url' => Server::$url]);
$t = microtime(true);
for ($i = 0; $i < $total; $i++) {
    $client->get('/guzzle-server/perf');
}
unset($client);
$totalTime = microtime(true) - $t;
$perRequest = ($totalTime / $total) * 1000;
printf("Future: %f (%f ms / request) %d total\n",
    $totalTime, $perRequest, $total);
