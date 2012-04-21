<?php

error_reporting(E_ALL | E_STRICT);

// Ensure that composer has installed all dependencies
if (!file_exists(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'composer.lock')) {
    die("Dependencies must be installed using composer:\n\nphp composer.phar install --dev\n\n"
        . "See http://getcomposer.org for help with installing composer\n");
}

require_once 'PHPUnit/TextUI/TestRunner.php';

// Inclue the phar files if testing against the phars
if (get_cfg_var('guzzle_phar')) {
    require get_cfg_var('guzzle_phar');
}

// Include the composer autoloader
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . '.composer' . DIRECTORY_SEPARATOR . 'autoload.php';

// Add the services file to the default service builder
$servicesFile = __DIR__ . DIRECTORY_SEPARATOR . 'Guzzle' . DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR . 'TestData' . DIRECTORY_SEPARATOR . 'services.xml';
Guzzle\Tests\GuzzleTestCase::setServiceBuilder(Guzzle\Service\ServiceBuilder::factory($servicesFile));

// Modify the include path so that it can find the Zend Framework
$paths = array('vendor/zend/zend-cache1', 'vendor/zend/zend-log1');
set_include_path(implode(PATH_SEPARATOR, array_map(function($path) {
    return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $path;
}, $paths)) . PATH_SEPARATOR . get_include_path());
