<?php
require __DIR__ . '/artifacts/Burgomaster.php';

$stageDirectory = __DIR__ . '/artifacts/staging';
$projectRoot = __DIR__ . '/../';
$packager = new \Burgomaster($stageDirectory, $projectRoot);

// Copy basic files to the stage directory. Note that we have chdir'd onto
// the $projectRoot directory, so use relative paths.
foreach (['README.md', 'LICENSE'] as $file) {
    $packager->deepCopy($file, $file);
}

// Copy each dependency to the staging directory. Copy *.php and *.pem files.
$packager->recursiveCopy('src', 'GuzzleHttp', ['php']);
$packager->recursiveCopy('vendor/react/promise/src', 'React/Promise');
$packager->recursiveCopy('vendor/guzzlehttp/ringphp/src', 'GuzzleHttp/Ring');
$packager->recursiveCopy('vendor/guzzlehttp/streams/src', 'GuzzleHttp/Stream');
$packager->createAutoloader(['React/Promise/functions.php']);
$packager->createPhar(__DIR__ . '/artifacts/guzzle.phar');
$packager->createZip(__DIR__ . '/artifacts/guzzle.zip');
