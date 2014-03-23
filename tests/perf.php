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

// Wait until the server is responding
Server::wait();

// Get custom make variables
$total = isset($_SERVER['REQUESTS']) ? $_SERVER['REQUESTS'] : 1000;
$parallel = isset($_SERVER['PARALLEL']) ? $_SERVER['PARALLEL'] : 25;

$client = new Client(['base_url' => Server::$url]);

$t = microtime(true);
for ($i = 0; $i < $total; $i++) {
    $client->get('/guzzle-server/perf');
}
$totalTime = microtime(true) - $t;
$perRequest = ($totalTime / $total) * 1000;
printf("Serial:   %f (%f ms / request) %d total\n",
    $totalTime, $perRequest, $total);

// Create a generator used to yield batches of requests to sendAll
$reqs = function () use ($client, $total) {
    for ($i = 0; $i < $total; $i++) {
        yield $client->createRequest('GET', '/guzzle-server/perf');
    }
};

$t = microtime(true);
$client->sendAll($reqs(), ['parallel' => $parallel]);
$totalTime = microtime(true) - $t;
$perRequest = ($totalTime / $total) * 1000;
printf("Parallel: %f (%f ms / request) %d total with %d in parallel\n",
    $totalTime, $perRequest, $total, $parallel);
