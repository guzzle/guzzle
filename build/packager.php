<?php

// Copy Burgomaster if it is not present
$packagerScript = __DIR__ . '/artifacts/Packager.php';
$packagerSource = 'https://raw.githubusercontent.com/mtdowling/Burgomaster/a4bc5e5600e07436187282fca059755161f8314e/src/Packager.php';

if (!file_exists($packagerScript)) {
    echo "Retrieving Burgomaster from $packagerSource\n";
    if (!is_dir(dirname($packagerScript))) {
        mkdir(dirname($packagerScript)) or die('Unable to create dir');
    }
    file_put_contents($packagerScript, file_get_contents($packagerSource));
    echo "> Downloaded Burgomaster\n\n";
}

require $packagerScript;

$packager = new \Burgomaster\Packager(
    realpath(__DIR__ . '/..') . '/build/artifacts/staging',
    __DIR__ . '/../'
);

foreach (['README.md', 'LICENSE'] as $file) {
    $packager->deepCopy($file, $file);
}

$packager->recursiveCopy('src', 'GuzzleHttp');
$packager->recursiveCopy('vendor/guzzlehttp/streams/src', 'GuzzleHttp/Stream');
$packager->createAutoloader(['GuzzleHttp/functions.php']);
$packager->createPhar(__DIR__ . '/artifacts/guzzle.phar');
$packager->createZip(__DIR__ . '/artifacts/guzzle.zip');
