<?php

namespace Guzzle\Tests;

error_reporting(E_ALL | E_STRICT);

require_once 'PHPUnit/TextUI/TestRunner.php';
require_once __DIR__ . '/../vendor/Symfony/Component/ClassLoader/UniversalClassLoader.php';

$classLoader = new \Symfony\Component\ClassLoader\UniversalClassLoader();
$classLoader->registerNamespaces(array(
    'Guzzle\Tests' => __DIR__,
    'Guzzle' => __DIR__ . '/../src',
    'Doctrine' => __DIR__ . '/../vendor/Doctrine/lib',
    'Monolog' => __DIR__ . '/../vendor/Monolog/src'
));
$classLoader->registerPrefix('Zend_',  __DIR__ . '/../vendor');
$classLoader->register();

$servicesFile = __DIR__ . '/Guzzle/Tests/TestData/services.xml';
GuzzleTestCase::setServiceBuilder(\Guzzle\Service\ServiceBuilder::factory($servicesFile));