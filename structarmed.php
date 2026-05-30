<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;

return Architecture::define()
    ->skip([
        // no namespace on purpose
        __DIR__ . '/tests/bootstrap-phpstan.php',

        // multiple classes in a file
        // can be splitted into multiple files if needed
        __DIR__ . '/tests/ClientTest.php',
        __DIR__ . '/tests/Exception/RequestExceptionTest.php',
        __DIR__ . '/tests/RedirectMiddlewareTest.php',
        __DIR__ . '/tests/PrepareBodyMiddlewareTest.php',
        __DIR__ . '/tests/UtilsTest.php',
    ])
    ->withPreset(Preset::PSR4());
