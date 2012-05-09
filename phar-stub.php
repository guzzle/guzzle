<?php

require_once 'phar://guzzle/vendor/symfony/class-loader/Symfony/Component/ClassLoader/UniversalClassLoader.php';

$classLoader = new Symfony\Component\ClassLoader\UniversalClassLoader();
$classLoader->registerNamespaces(array(
    'Guzzle' => 'phar://guzzle/src',
    'Symfony\\Component\\EventDispatcher' => 'phar://guzzle/vendor/symfony/event-dispatcher',
    'Doctrine' => 'phar://guzzle/vendor/doctrine/common/lib',
    'Monolog' => 'phar://guzzle/vendor/monolog/monolog/src'
));
$classLoader->register();

__HALT_COMPILER();
