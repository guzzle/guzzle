<?php
// php probe.php <port> <names-file> [variant]
if (is_file(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
} else {
    require '/tmp/claude-guzzle-3936/plan/other/exp/autoload.php';
}

use GuzzleHttp\Psr7\Request;

$port = $argv[1];
$names = array_filter(array_map('trim', file($argv[2])));
$D = implode('', array_map(fn ($i) => sprintf("%07d\n", $i), range(0, 1999)));
$classify = static function (string $b) use ($D): string {
    if ($b === $D) return 'PLAIN';
    if (strlen($b) < 64) return json_encode($b);
    $dec = @gzdecode($b);
    if ($dec === $D) return 'GZIP(D)';
    if (preg_match('/^[0-9a-f]+\r\n/', $b)) return 'RAW-CHUNK-FRAMING';
    if ($dec !== false && preg_match('/^[0-9a-f]+\r\n/', $dec)) return 'GZIP(CHUNKED)';
    if (str_starts_with($D, $b)) return 'PREFIX('.strlen($b).')';
    return 'OTHER('.strlen($b).')';
};
$short = static function (Throwable $e): string {
    $m = (new ReflectionClass($e))->getShortName().': '.preg_replace('/ \(see https[^)]*\)( for \S+)?/', '', preg_replace('/\s+/', ' ', $e->getMessage()));
    return substr($m, 0, 110);
};
$handlers = ['stream' => new GuzzleHttp\Handler\StreamHandler(), 'curl' => new GuzzleHttp\Handler\CurlHandler()];
$cv = curl_version();
echo 'PHP '.PHP_VERSION.' '.PHP_OS_FAMILY.' libcurl '.$cv['version'].' zlib '.($cv['libz_version'] ?? '?')."\n";
set_error_handler(static fn () => true);
foreach ($names as $name) {
    $res = [];
    foreach ([['stream', 'buf'], ['stream', 'str'], ['curl', 'buf']] as [$h, $mode]) {
        $opts = ['read_timeout' => 5, 'timeout' => 15, 'decode_content' => true];
        if ($mode === 'str') {
            $opts['stream'] = true;
        }
        try {
            $response = $handlers[$h](new Request('GET', "http://127.0.0.1:$port/$name"), $opts)->wait();
        } catch (Throwable $e) {
            $res[] = "$h/$mode: REJECT ".$short($e);
            continue;
        }
        $te = $response->hasHeader('Transfer-Encoding') ? ' TE='.json_encode($response->getHeaderLine('Transfer-Encoding')) : '';
        $body = $response->getBody();
        $b = '';
        try {
            while (!$body->eof()) {
                $d = $body->read(65536);
                if ($d === '' && $body->eof()) {
                    break;
                }
                $b .= $d;
            }
            $res[] = "$h/$mode: OK$te ".$classify($b);
        } catch (Throwable $e) {
            $res[] = "$h/$mode: READ-ERROR$te after ".strlen($b).' '.$short($e);
        }
    }
    echo "### $name\n   ".implode("\n   ", $res)."\n";
}
