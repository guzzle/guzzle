<?php
// Usage: php matrix.php <guzzle-root> <port> [filter]
require $argv[1].'/vendor/autoload.php';
require __DIR__.'/fixtures.php';
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;

$port = (int) $argv[2];
$filter = $argv[3] ?? null;
$notices = [];
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$notices): bool {
    $notices[] = $msg.' @'.basename($file).':'.$line;
    return true;
});
$bodies = fixtureBodies();
foreach ($bodies as $name => [$enc, $encoded, $plain]) {
    foreach (['close', 'length', 'chunked'] as $framing) {
        foreach (['same', 'split'] as $timing) {
            foreach (['stream', 'buffer'] as $mode) {
                $key = "$name/$framing/$timing/$mode";
                if ($filter !== null && !preg_match('{'.$filter.'}', $key)) { continue; }
                $notices = [];
                $onHeaders = false;
                $handler = getenv("HANDLER") === "curl" ? new \GuzzleHttp\Handler\CurlHandler() : new StreamHandler();
                $opts = ['decode_content' => true, 'read_timeout' => 5, 'on_headers' => static function () use (&$onHeaders) { $onHeaders = true; }];
                if ($mode === 'stream') { $opts['stream'] = true; }
                $result = '';
                try {
                    $response = $handler(new Request('GET', "http://127.0.0.1:$port/$name/$framing/$timing"), $opts)->wait();
                    $body = $response->getBody();
                    $got = '';
                    $err = null;
                    try {
                        while (!$body->eof()) { $c = $body->read(8192); if ($c === '' && $body->eof()) { break; } $got .= $c; }
                    } catch (\Throwable $e) {
                        $err = get_class($e).': '.$e->getMessage().($e->getPrevious() ? ' <- '.get_class($e->getPrevious()).': '.$e->getPrevious()->getMessage() : '');
                    }
                    $match = $got === $plain ? 'FULL' : (strncmp($plain, $got, strlen($got)) === 0 ? 'PREFIX('.strlen($got).'/'.strlen($plain).')' : 'GARBAGE('.strlen($got).')');
                    $result = 'resolved; body='.$match.($err ? '; read-error='.$err : '');
                    $body->close();
                } catch (\Throwable $e) {
                    $result = 'REJECTED '.get_class($e).': '.$e->getMessage().($e->getPrevious() ? ' <- '.get_class($e->getPrevious()).': '.substr($e->getPrevious()->getMessage(), 0, 150) : '');
                }
                printf("%-40s on_headers=%s %s%s\n", $key, $onHeaders ? 'y' : 'n', $result, $notices ? ' NOTICES='.implode(' | ', array_unique($notices)) : '');
            }
        }
    }
}
