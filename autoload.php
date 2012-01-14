<?php

$namespaces = array(
    'Guzzle' => 'phar://' . __FILE__ . '/src',
    'Symfony\\Component\\Validator' => 'phar://' . __FILE__ . '/vendor/symfony/validator',
    'Symfony\\Component\\EventDispatcher' => 'phar://' . __FILE__ . '/vendor/symfony/event-dispatcher',
    'Doctrine' => 'phar://' . __FILE__ . '/vendor/doctrine/common/lib',
    'Monolog' => 'phar://' . __FILE__ . '/vendor/monolog/monolog/src'
);

if (DIRECTORY_SEPARATOR == '/') {
    require_once 'phar://' . __FILE__ . '/vendor/symfony/class-loader/Symfony/Component/ClassLoader/UniversalClassLoader.php';
} else {
    require_once 'phar://' . __FILE__ . '\\vendor\\symfony\\class-loader\\Symfony\\Component\\ClassLoader\\UniversalClassLoader.php';
    $namespaces = array_filter($namespaces, function($namespace) {
        return str_replace('phar:\\\\', 'phar://', str_replace('/', '\\', $namespace));
    });
}

$classLoader = new Symfony\Component\ClassLoader\UniversalClassLoader();
$classLoader->registerNamespaces($namespaces);
$classLoader->register();

__HALT_COMPILER();