<?php
// Shared body fixtures for the experiments.
function fixtureBodies(): array
{
    $plain = '';
    for ($i = 0; $i < 3000; $i++) {
        $plain .= sprintf("line %05d %s\n", $i, md5((string) $i));
    }
    $gzip = gzencode($plain);
    $mid = intdiv(strlen($gzip), 2);
    $corrupt = $gzip;
    for ($i = 0; $i < 64; $i++) { $corrupt[$mid + $i] = chr(ord($corrupt[$mid + $i]) ^ 0xff); }
    $badCrc = $gzip;
    $badCrc[strlen($badCrc) - 8] = chr(ord($badCrc[strlen($badCrc) - 8]) ^ 0xff);

    return [
        'gzip' => ['gzip', $gzip, $plain],
        'zlib' => ['deflate', gzcompress($plain), $plain],
        'gzip-as-deflate' => ['deflate', $gzip, $plain],
        'zlib-as-gzip' => ['gzip', gzcompress($plain), $plain],
        'raw-deflate' => ['deflate', gzdeflate($plain), $plain],
        'mislabelled' => ['gzip', $plain, $plain],
        'corrupt-mid' => ['gzip', $corrupt, $plain],
        'bad-crc' => ['gzip', $badCrc, $plain],
        'truncated-trailer' => ['gzip', substr($gzip, 0, -8), $plain],
        'truncated-half' => ['gzip', substr($gzip, 0, $mid), $plain],
        'truncated-1' => ['gzip', substr($gzip, 0, 1), ''],
        'concatenated' => ['gzip', $gzip.gzencode('SECOND'), $plain],
        'trailing-garbage' => ['gzip', $gzip.'garbage', $plain],
        'empty' => ['gzip', '', ''],
    ];
}
