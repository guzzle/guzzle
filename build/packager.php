<?php
require __DIR__ . '/artifacts/Burgomaster.php';

// Creating staging directory at guzzlehttp/src/build/artifacts/staging.
$stageDirectory = __DIR__ . '/artifacts/staging';
// The root of the project is up one directory from the current directory.
$projectRoot = __DIR__ . '/../';
$packager = new \Burgomaster($stageDirectory, $projectRoot);

// Copy basic files to the stage directory. Note that we have chdir'd onto
// the $projectRoot directory, so use relative paths.
foreach (['README.md', 'LICENSE'] as $file) {
    $packager->deepCopy($file, $file);
}

// Copy each dependency to the staging directory. Copy *.php and *.pem files.
$packager->recursiveCopy('src', 'GuzzleHttp', ['php', 'pem']);
$packager->recursiveCopy('vendor/guzzlehttp/streams/src', 'GuzzleHttp/Stream');
// Create the classmap autoloader, and instruct the autoloader to
// automatically require the 'GuzzleHttp/functions.php' script.
$packager->createAutoloader(['GuzzleHttp/functions.php']);
// Create a phar file from the staging directory at a specific location
$packager->createPhar(__DIR__ . '/artifacts/guzzle.phar');
// Create a zip file from the staging directory at a specific location
$packager->createZip(__DIR__ . '/artifacts/guzzle.zip');
