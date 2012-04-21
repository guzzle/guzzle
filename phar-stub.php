<?php

require_once 'phar://Guzzle/vendor/symfony/class-loader/Symfony/Component/ClassLoader/UniversalClassLoader.php';

$classLoader = new Symfony\Component\ClassLoader\UniversalClassLoader();
$classLoader->registerNamespaces(array(
    'Guzzle' => 'phar://Guzzle/src',
    'Symfony\\Component\\Validator' => 'phar://Guzzle/vendor/symfony/validator',
    'Symfony\\Component\\EventDispatcher' => 'phar://Guzzle/vendor/symfony/event-dispatcher',
    'Doctrine' => 'phar://Guzzle/vendor/doctrine/common/lib',
    'Monolog' => 'phar://Guzzle/vendor/monolog/monolog/src'
));
$classLoader->register();

__HALT_COMPILER();
