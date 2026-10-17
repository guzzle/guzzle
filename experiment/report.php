<?php
// Temporary CI experiment driver: php experiment/report.php
$root = dirname(__DIR__);
$php = PHP_BINARY;
$dir = sys_get_temp_dir().'/pr-b-exp-'.getmypid();
@mkdir($dir);
$procs = [];
$start = static function (string $script, array $args, string $portFile) use ($php, &$procs) {
    $procs[] = proc_open(array_merge([$php, $script], $args), [['pipe', 'r'], ['file', $portFile.'.log', 'a'], ['file', $portFile.'.log', 'a']], $pipes);
    for ($i = 0; $i < 100 && !is_file($portFile); $i++) { usleep(100000); }
    usleep(200000);
    return trim((string) @file_get_contents($portFile));
};
$raw = $start(__DIR__.'/rawserver.php', [$dir.'/raw.port'], $dir.'/raw.port');
$drip = $start(__DIR__.'/dribble-server.php', [$dir.'/drip.port'], $dir.'/drip.port');
echo 'PHP ', PHP_VERSION, ' ', PHP_OS_FAMILY, ' zlib ', ZLIB_VERSION, ' curl ', function_exists('curl_version') ? curl_version()['version'].' libz '.curl_version()['libz_version'] : '-', " raw=$raw drip=$drip\n";
$run = static function (array $env, array $args) use ($php): string {
    $proc = proc_open(array_merge([$php, '-d', 'memory_limit=2G'], $args), [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    proc_close($proc);
    return $out;
};
$collapse = static function (string $out): string {
    // Collapse framing/timing variants that produced identical results.
    $groups = [];
    foreach (explode("\n", trim($out)) as $line) {
        if (!preg_match('{^(\S+?)/(\S+?)/(\S+?)/(\S+)\s+(.*)$}', $line, $m)) { $groups[$line] = [$line]; continue; }
        $result = preg_replace('{http://127\.0\.0\.1:\d+/\S+}', 'URL', $m[5]);
        $result = preg_replace('{(GARBAGE|PREFIX)\(\d+}', '$1(n', $result);
        $groups[$m[1].' '.$m[4].' | '.$result][] = $m[2].'/'.$m[3];
    }
    $o = '';
    foreach ($groups as $k => $v) { $o .= '  '.$k.(count($v) === 6 ? '' : ' ['.implode(',', $v).']')."\n"; }
    return $o;
};
foreach ([
    'base 8.2 (filters + psr7 InflateStream)' => ['GUZZLE_EXP_BASE' => 'none', 'GUZZLE_EXP_DECODER' => 'psr7'],
    'A+B (IncrementalStream + InflateBodyStream)' => [],
    'filter-free (userland dechunk + InflateBodyStream)' => ['GUZZLE_EXP_BASE' => 'userland'],
    'cURL handler' => ['HANDLER' => 'curl'],
] as $name => $env) {
    echo "== matrix: $name\n", $collapse($run($env, [__DIR__.'/matrix.php', $root, $raw]));
}
foreach (['gzip', 'gzip-cl', 'gzip-chunked'] as $mode) {
    foreach (['base' => ['GUZZLE_EXP_BASE' => 'none', 'GUZZLE_EXP_DECODER' => 'psr7'], 'A+B' => [], 'filter-free' => ['GUZZLE_EXP_BASE' => 'userland']] as $name => $env) {
        echo "== latency $mode $name: ", trim($run($env, [__DIR__.'/latency.php', $root, "http://127.0.0.1:$drip/$mode"])), "\n";
    }
}
echo "== bench-stack\n", $run([], [__DIR__.'/bench-stack.php', $root, '32', '5']);
echo "== bench-plain\n", $run([], [__DIR__.'/bench-plain.php', $root, '32', '5', '16384']), $run([], [__DIR__.'/bench-plain.php', $root, '32', '5', '1024']);
foreach ($procs as $p) { proc_terminate($p); }
