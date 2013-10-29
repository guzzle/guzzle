<?php

Phar::mapPhar('guzzle.phar');

require_once 'phar://guzzle.phar/vendor/symfony/class-loader/Symfony/Component/ClassLoader/UniversalClassLoader.php';

$classLoader = new Symfony\Component\ClassLoader\UniversalClassLoader();
$classLoader->registerNamespaces(array(
    'Guzzle' => 'phar://guzzle.phar/src',
    'Symfony\\Component\\EventDispatcher' => 'phar://guzzle.phar/vendor/symfony/event-dispatcher'
));
$classLoader->register();

__HALT_COMPILER();
