<?php
require __DIR__."/../vendor/autoload.php";

// Test HTTP server that dribbles response bodies. Usage: php server.php <portfile> [tls-cert-pem]

$portFile = $argv[1];
$cert = $argv[2] ?? null;

$ctx = stream_context_create($cert ? ['ssl' => ['local_cert' => $cert, 'allow_self_signed' => true, 'verify_peer' => false]] : []);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if (!$server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
file_put_contents($portFile, substr($name, strrpos($name, ':') + 1));

$events = 5;
$gap = 400000; // 400ms between events

function evt(int $i): string
{
    return sprintf("data: event %d %s\n\n", $i, str_repeat('x', 20));
}

while (true) {
    $conn = @stream_socket_accept($server, 600);
    if ($conn === false) {
        continue;
    }
    $pid = function_exists('pcntl_fork') ? pcntl_fork() : -1;
    if ($pid > 0) {
        fclose($conn);
        while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
        }
        continue;
    }

    if ($cert) {
        stream_context_set_option($conn, 'ssl', 'local_cert', $cert);
        stream_context_set_option($conn, 'ssl', 'verify_peer', false);
        stream_set_blocking($conn, true);
        if (!@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
            fclose($conn);
            exit(0);
        }
    }
    $req = '';
    while (!str_contains($req, "\r\n\r\n") && ($line = fgets($conn)) !== false) {
        $req .= $line;
    }
    [$method, $path] = explode(' ', $req) + [null, null];
    $path = (string) $path;
    $q = [];
    parse_str((string) parse_url($path, PHP_URL_QUERY), $q);
    $mode = trim((string) parse_url($path, PHP_URL_PATH), '/');
    $status = $q['status'] ?? '200 OK';
    $proto = isset($q['http10']) ? 'HTTP/1.0' : 'HTTP/1.1';

    $pieces = [];
    for ($i = 0; $i < $events; $i++) {
        $pieces[] = evt($i);
    }

    $enc = null;
    if (str_starts_with($mode, 'gzip') || str_starts_with($mode, 'deflate')) {
        $z = deflate_init(str_starts_with($mode, 'gzip') ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_DEFLATE);
        $out = [];
        foreach ($pieces as $i => $p) {
            $out[] = deflate_add($z, $p, $i === count($pieces) - 1 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
        }
        $pieces = $out;
        $enc = str_starts_with($mode, 'gzip') ? 'gzip' : 'deflate';
    } elseif (str_starts_with($mode, 'br')) {
        $enc = 'br';
    }
    $chunked = str_contains($mode, 'chunked');
    $cl = str_ends_with($mode, '-cl') || $mode === 'cl';

    $h = "$proto $status\r\n";
    if ($enc) {
        $h .= "Content-Encoding: $enc\r\n";
    }
    if ($chunked) {
        $h .= "Transfer-Encoding: chunked\r\n";
    } elseif ($cl) {
        $h .= 'Content-Length: '.strlen(implode('', $pieces))."\r\n";
    }
    $h .= "Connection: close\r\n\r\n";
    fwrite($conn, $h);

    if ($method !== 'HEAD' && !in_array(substr($status, 0, 3), ['204', '304'], true)) {
        foreach ($pieces as $i => $p) {
            usleep($gap);
            fwrite($conn, $chunked ? dechex(strlen($p))."\r\n".$p."\r\n" : $p);
        }
        if ($chunked) {
            usleep($gap);
            fwrite($conn, "0\r\n\r\n");
        }
    }
    fclose($conn);
    if ($pid === 0) {
        exit(0);
    }
}
