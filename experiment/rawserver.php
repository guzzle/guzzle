<?php
// Raw HTTP server: GET /<body>/<framing>/<timing>. framing: close|length|chunked. timing: same|split
require __DIR__.'/fixtures.php';
$portFile = $argv[1];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$name = stream_socket_get_name($server, false);
file_put_contents($portFile, substr($name, strrpos($name, ':') + 1));
$bodies = fixtureBodies();
while (true) {
    $conn = @stream_socket_accept($server, 600);
    if ($conn === false) { continue; }
    $req = '';
    while (strpos($req, "\r\n\r\n") === false && ($line = fgets($conn)) !== false) { $req .= $line; }
    $path = explode(' ', $req)[1] ?? '/';
    [, $body, $framing, $timing] = explode('/', $path) + [null, 'gzip', 'close', 'same'];
    [$encoding, $encoded] = $bodies[$body] ?? ['identity', 'unknown fixture'];
    $headers = "HTTP/1.1 200 OK\r\nContent-Encoding: {$encoding}\r\nConnection: close\r\n";
    if ($framing === 'length') { $headers .= 'Content-Length: '.strlen($encoded)."\r\n"; }
    if ($framing === 'chunked') {
        $headers .= "Transfer-Encoding: chunked\r\n";
        $wire = '';
        foreach (str_split($encoded, 1000) as $c) { if ($c !== '') { $wire .= dechex(strlen($c))."\r\n{$c}\r\n"; } }
        $wire .= "0\r\n\r\n";
    } else {
        $wire = $encoded;
    }
    $headers .= "\r\n";
    if ($timing === 'split') {
        fwrite($conn, $headers);
        usleep(150000);
        fwrite($conn, $wire);
    } else {
        fwrite($conn, $headers.$wire);
    }
    usleep(50000);
    fclose($conn);
}
