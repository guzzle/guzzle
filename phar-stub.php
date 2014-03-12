<?php

Phar::mapPhar('guzzle.phar');

require_once 'phar://guzzle.phar/vendor/symfony/class-loader/Symfony/Component/ClassLoader/UniversalClassLoader.php';

$classLoader = new Symfony\Component\ClassLoader\UniversalClassLoader();
$classLoader->registerNamespaces(array(
    'Guzzle' => 'phar://guzzle.phar/src',
    'Symfony\\Component\\EventDispatcher' => 'phar://guzzle.phar/vendor/symfony/event-dispatcher',
    'Doctrine' => 'phar://guzzle.phar/vendor/doctrine/common/lib',
    'Monolog' => 'phar://guzzle.phar/vendor/monolog/monolog/src'
));
$classLoader->register();

// Copy the cacert.pem file from the phar if it is not in the temp folder.
$from = 'phar://guzzle.phar/src/Guzzle/Http/Resources/cacert.pem';
$certFile = sys_get_temp_dir() . '/guzzle-cacert.pem';

if (!copy($from, $certFile)) {
    throw new RuntimeException("Could not copy {$from} to {$certFile}: "
        . var_export(error_get_last(), true));
}

__HALT_COMPILER();
