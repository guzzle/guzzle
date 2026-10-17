<?php
// Plain chunked throughput: dechunk filter vs naive userland dechunker (CPU time). Usage: php bench-plain.php <guzzle-root> [MB] [reps] [chunk]
require $argv[1].'/vendor/autoload.php';
use GuzzleHttp\Handler\ExpChunkedStream;
use GuzzleHttp\Psr7;
$mb = (int) ($argv[2] ?? 32); $reps = (int) ($argv[3] ?? 7); $cs = (int) ($argv[4] ?? 16384);
$plain = str_repeat('0123456789abcdef', $mb * 65536);
$wire = ''; foreach (str_split($plain, $cs) as $c) { $wire .= dechex(strlen($c))."\r\n$c\r\n"; } $wire .= "0\r\n\r\n";
$cpu = static function (): float { $r = getrusage(); return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6; };
$res = static function (string $data) { $r = fopen('php://temp', 'r+'); fwrite($r, $data); rewind($r); return $r; };
$variants = [
    'dechunk filter' => static function () use ($res, $wire) { $r = $res($wire); stream_filter_append($r, 'dechunk', STREAM_FILTER_READ); return new Psr7\Stream($r); },
    'naive userland dechunk' => static fn () => new ExpChunkedStream(new Psr7\Stream($res($wire))),
];
$best = array_fill_keys(array_keys($variants), INF);
for ($i = 0; $i < $reps; $i++) {
    foreach ($variants as $n => $f) { $s = $f(); $c = $cpu(); $len = 0; while (!$s->eof()) { $d = $s->read(8192); if ($d === '') break; $len += strlen($d); } $best[$n] = min($best[$n], $cpu() - $c); if ($len !== strlen($plain)) echo "MISMATCH $n\n"; }
}
foreach ($best as $n => $t) printf("plain chunk=%d %-24s cpu %.3fs %6.0f MB/s\n", $cs, $n, $t, $mb / $t);
