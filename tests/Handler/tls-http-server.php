<?php

declare(strict_types=1);

/*
 * Minimal HTTPS server fixture for the stream handler TLS session sharing
 * tests. Serves a fixed body to each connection until it is terminated and
 * reports whether the handshake resumed a TLS session in the
 * X-TLS-Session-Reused response header. Usage: php tls-http-server.php
 * <certificate-pem-path> [tls1.2]. The bound address is printed on stdout
 * once the server is listening.
 */

$certificate = $argv[1] ?? '';
if ($certificate === '' || !\is_file($certificate)) {
    \fwrite(\STDERR, "A certificate PEM path is required.\n");
    exit(1);
}

$mode = $argv[2] ?? null;
if ($mode !== null && $mode !== 'tls1.2') {
    \fwrite(\STDERR, "The optional mode must be tls1.2.\n");
    exit(1);
}

// Enabling the server-side session cache makes PHP 8.6 create the server
// SSL_CTX when the socket starts listening and share it across accepted
// connections. Without it every accept builds a fresh SSL_CTX, so TLS 1.2
// session IDs land in per-connection caches and TLS 1.3 tickets are sealed
// with per-connection ticket keys, and no handshake can ever resume. The
// session_id_context is required because the server context defaults to
// SSL_VERIFY_PEER. Older runtimes ignore both options.
$ssl = [
    'local_cert' => $certificate,
    'session_cache' => true,
    'session_id_context' => 'guzzle-test',
];
if ($mode === 'tls1.2') {
    $ssl['min_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_2;
    $ssl['max_proto_version'] = \STREAM_CRYPTO_PROTO_TLSv1_2;
}
$context = \stream_context_create(['ssl' => $ssl]);
$server = \stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    \fwrite(\STDERR, "Unable to start the TLS server: $errstr\n");
    exit(1);
}

\fwrite(\STDOUT, \stream_socket_get_name($server, false)."\n");

while (($connection = @\stream_socket_accept($server, 30)) !== false) {
    @\fread($connection, 8192);
    // PHP 8.6 reports whether the accepted handshake resumed a TLS session;
    // older runtimes omit the flag and always report 0.
    $metadata = \stream_get_meta_data($connection);
    $reused = !empty($metadata['crypto']['session_reused']) ? '1' : '0';
    @\fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nX-TLS-Session-Reused: $reused\r\nConnection: close\r\n\r\nok");
    @\fclose($connection);
}
