<?php
// In-memory stack throughput using client CPU time. Usage: php bench-stack.php <guzzle-root> [MB] [reps]
require $argv[1].'/vendor/autoload.php';
use GuzzleHttp\Handler\ExpChunkedStream;
use GuzzleHttp\Handler\InflateBodyStream;
use GuzzleHttp\Psr7;

$mb = (int) ($argv[2] ?? 32);
$reps = (int) ($argv[3] ?? 7);
$target = $mb * 1048576;
mt_srand(1);
$text = '';
while (strlen($text) < $target) { $text .= sprintf("%08d %s %s\n", strlen($text), md5((string) strlen($text)), str_repeat(chr(97 + mt_rand(0, 25)), mt_rand(1, 40))); }
$cpu = static function (): float { $r = getrusage(); return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6; };
$res = static function (string $data) { $r = fopen('php://temp', 'r+'); fwrite($r, $data); rewind($r); return $r; };
$chunk = static function (string $data): string { $o = ''; foreach (str_split($data, 16384) as $c) { $o .= dechex(strlen($c))."\r\n$c\r\n"; } return $o."0\r\n\r\n"; };
foreach (['text' => $text, 'zeros' => str_repeat("\0", $target), 'random' => substr(str_repeat(random_bytes(1048576), $mb), 0, $target)] as $name => $plain) {
    $gz = gzencode($plain, 6);
    $gzc = $chunk($gz);
    $variants = [
        'close: zlib filter on resource (#3936 style)' => static function () use ($res, $gz) { $r = $res($gz); stream_filter_append($r, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 47]); return new Psr7\Stream($r); },
        'close: psr7 InflateStream (base)' => static fn () => new Psr7\InflateStream(new Psr7\Stream($res($gz))),
        'close: InflateBodyStream' => static fn () => new InflateBodyStream(new Psr7\Stream($res($gz))),
        'chunked: dechunk+zlib filters on resource (#3936 style)' => static function () use ($res, $gzc) { $r = $res($gzc); stream_filter_append($r, 'dechunk', STREAM_FILTER_READ); stream_filter_append($r, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 47]); return new Psr7\Stream($r); },
        'chunked: dechunk filter + psr7 InflateStream (base)' => static function () use ($res, $gzc) { $r = $res($gzc); stream_filter_append($r, 'dechunk', STREAM_FILTER_READ); return new Psr7\InflateStream(new Psr7\Stream($r)); },
        'chunked: dechunk filter + InflateBodyStream' => static function () use ($res, $gzc) { $r = $res($gzc); stream_filter_append($r, 'dechunk', STREAM_FILTER_READ); return new InflateBodyStream(new Psr7\Stream($r)); },
        'chunked: userland dechunk + InflateBodyStream' => static fn () => new InflateBodyStream(new ExpChunkedStream(new Psr7\Stream($res($gzc)))),
    ];
    $best = array_fill_keys(array_keys($variants), INF);
    for ($rep = 0; $rep < $reps; $rep++) {
        foreach ($variants as $vname => $factory) {
            $s = $factory();
            $c = $cpu();
            $n = 0;
            while (!$s->eof()) { $d = $s->read(8192); if ($d === '') { break; } $n += strlen($d); }
            $best[$vname] = min($best[$vname], $cpu() - $c);
            if ($n !== strlen($plain)) { echo "MISMATCH $vname $n\n"; }
            $s->close();
        }
    }
    foreach ($best as $vname => $t) { printf("%-6s %-58s cpu %.3fs  %6.0f MB/s\n", $name, $vname, $t, $mb / $t); }
}
echo 'PHP ', PHP_VERSION, ' zlib ', ZLIB_VERSION, ' ', PHP_OS_FAMILY, "\n";
