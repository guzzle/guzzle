<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\StreamTlsSessionCache;
use Openssl\Session;
use PHPUnit\Framework\Assert;

/**
 * Captures real Openssl\Session instances from a loopback TLS handshake for PHP
 * 8.6-gated tests.
 */
final class LoopbackTlsSession
{
    /**
     * Performs a loopback TLS handshake and returns a session captured from the
     * client's session_new_cb. Unsupported runtimes are skipped; capture
     * failures on supported runtimes fail the calling test.
     */
    public static function capture(int $clientCryptoMethod, int $serverCryptoMethod): Session
    {
        if (!StreamTlsSessionCache::isSupported()) {
            Assert::markTestSkipped('This test requires PHP 8.6+ with the OpenSSL session API.');
        }

        $certificate = self::createSelfSignedCertificate();

        try {
            $serverContext = \stream_context_create(['ssl' => ['local_cert' => $certificate]]);
            $server = \stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $serverContext);
            Assert::assertNotFalse($server, "Unable to create the loopback server: $errstr");

            $address = \stream_socket_get_name($server, false);
            Assert::assertIsString($address);

            $sessions = [];
            $clientContext = \stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'session_new_cb' => static function ($stream, Session $session) use (&$sessions): void {
                        $sessions[] = $session;
                    },
                ],
            ]);

            $client = \stream_socket_client("tcp://$address", $errno, $errstr, 5, \STREAM_CLIENT_CONNECT, $clientContext);
            Assert::assertNotFalse($client, "Unable to connect the loopback client: $errstr");

            $accepted = \stream_socket_accept($server, 5);
            Assert::assertNotFalse($accepted, 'Unable to accept the loopback connection.');

            \stream_set_blocking($client, false);
            \stream_set_blocking($accepted, false);

            $clientReady = false;
            $serverReady = false;
            $deadline = \microtime(true) + 10;

            while ((!$clientReady || !$serverReady) && \microtime(true) < $deadline) {
                if (!$clientReady) {
                    $result = @\stream_socket_enable_crypto($client, true, $clientCryptoMethod);
                    if ($result === true) {
                        $clientReady = true;
                    } elseif ($result === false) {
                        Assert::fail('The loopback TLS handshake failed on the client side.');
                    }
                }

                if (!$serverReady) {
                    $result = @\stream_socket_enable_crypto($accepted, true, $serverCryptoMethod);
                    if ($result === true) {
                        $serverReady = true;
                    } elseif ($result === false) {
                        Assert::fail('The loopback TLS handshake failed on the server side.');
                    }
                }

                \usleep(1000);
            }

            if (!$clientReady || !$serverReady) {
                Assert::fail('The loopback TLS handshake did not complete in time.');
            }

            // TLS 1.3 delivers sessions via post-handshake tickets, so pump
            // application data until the client has processed one.
            \fwrite($accepted, 'ticket');
            $deadline = \microtime(true) + 5;
            while ($sessions === [] && \microtime(true) < $deadline) {
                @\fread($client, 8192);
                \usleep(1000);
            }

            \fclose($client);
            \fclose($accepted);
            \fclose($server);

            if ($sessions === []) {
                Assert::fail('No TLS session was captured on the loopback connection.');
            }

            return $sessions[0];
        } finally {
            @\unlink($certificate);
        }
    }

    /**
     * Writes a throwaway self-signed certificate and key for the loopback
     * server to a temporary PEM file and returns its path.
     */
    public static function createSelfSignedCertificate(): string
    {
        $key = \openssl_pkey_new([
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($key === false) {
            Assert::fail('Unable to generate a private key for the loopback server.');
        }

        $csr = \openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            Assert::fail('Unable to generate a certificate signing request for the loopback server.');
        }

        $certificate = \openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        if ($certificate === false) {
            Assert::fail('Unable to self-sign a certificate for the loopback server.');
        }

        \openssl_x509_export($certificate, $certificatePem);
        \openssl_pkey_export($key, $keyPem);

        $path = \tempnam(\sys_get_temp_dir(), 'guzzle-test-tls-');
        if ($path === false) {
            Assert::fail('Unable to create a temporary certificate file.');
        }

        \file_put_contents($path, $certificatePem.$keyPem);

        return $path;
    }
}
