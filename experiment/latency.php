<?php
// Usage: php latency.php <guzzle-root> <url> [readSize]
require $argv[1].'/vendor/autoload.php';
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
$size = (int) ($argv[3] ?? 8192);
$t0 = microtime(true);
$response = (new StreamHandler())(new Request('GET', $argv[2]), ['stream' => true, 'decode_content' => true, 'read_timeout' => 10, 'verify' => false])->wait();
$body = $response->getBody();
$times = [];
while (!$body->eof()) {
    $d = $body->read($size);
    if ($d !== '') { $times[] = sprintf('%.1f:%d', microtime(true) - $t0, substr_count($d, 'data:')); }
}
echo get_class($body), ' ', implode(' ', $times), "\n";
