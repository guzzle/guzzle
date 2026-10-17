<?php

namespace GuzzleHttp\Tests\Handler;

use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Server\Server;
use GuzzleHttp\Utils;
use PHPUnit\Framework\TestCase;

/**
 * Temporary CI diagnostics for the read_timeout experiment. Not for merge.
 */
class ReadTimeoutExperimentDiagnosticsTest extends TestCase
{
    public function testPrintsDiagnostics()
    {
        $out = \sprintf("\n[rt-diag] PHP %s on %s\n", \PHP_VERSION, \PHP_OS);

        $variants = [
            'before' => static function ($resource, $readTimeout) {
                $sec = (int) $readTimeout;
                $usec = ($readTimeout - $sec) * 100000;
                \stream_set_timeout($resource, $sec, $usec);
            },
            'trunc' => static function ($resource, $readTimeout) {
                $sec = (int) $readTimeout;
                $usec = (int) (($readTimeout - $sec) * 1000000);
                \stream_set_timeout($resource, $sec, $usec);
            },
            'round' => static function ($resource, $readTimeout) {
                $sec = (int) $readTimeout;
                $usec = (int) \round(($readTimeout - $sec) * 1000000);
                \stream_set_timeout($resource, $sec, $usec);
            },
        ];

        \stream_wrapper_register('rtdiag', ReadTimeoutRecordingStreamWrapper::class);

        try {
            foreach ([0.5, 1.5, 2.3, 0.9999996, -0.5, \INF, \NAN, 1e20] as $input) {
                $row = [];
                foreach ($variants as $name => $fn) {
                    $errors = [];
                    \set_error_handler(static function ($no, $msg) use (&$errors) {
                        $errors[] = $no.':'.\substr($msg, 0, 70);

                        return true;
                    });
                    ReadTimeoutRecordingStreamWrapper::$timeouts = [];
                    $resource = \fopen('rtdiag://x', 'r');

                    try {
                        $fn($resource, $input);
                        $result = ReadTimeoutRecordingStreamWrapper::$timeouts === []
                            ? 'not-set'
                            : \implode(',', ReadTimeoutRecordingStreamWrapper::$timeouts[0]);
                    } catch (\Throwable $e) {
                        $result = \get_class($e);
                    } finally {
                        \restore_error_handler();
                    }

                    $row[] = $name.'='.$result.($errors === [] ? '' : ' {'.\implode(' | ', $errors).'}');
                }
                $out .= '[rt-diag] '.\var_export($input, true).': '.\implode('  ', $row)."\n";
            }
        } finally {
            \stream_wrapper_unregister('rtdiag');
        }

        Server::flush();
        $response = (new StreamHandler())(
            new Request('GET', Server::$url.'guzzle-server/read-timeout'),
            [
                RequestOptions::READ_TIMEOUT => 1.5,
                RequestOptions::STREAM => true,
            ]
        )->wait();
        $body = $response->getBody()->detach();
        \fgets($body);
        $s = Utils::currentTime();
        \fgets($body);
        $out .= \sprintf("[rt-diag] read_timeout 1.5 elapsed %.4f\n", Utils::currentTime() - $s);

        \fwrite(\STDERR, $out);

        self::assertTrue(true);
    }
}
