<?php

namespace GuzzleHttp\Tests;

trait DeprecationTestTrait
{
    /**
     * @return mixed
     */
    private function withoutDeprecations(callable $callback)
    {
        \set_error_handler(static function (int $severity): bool {
            return $severity === \E_USER_DEPRECATED;
        });

        try {
            return $callback();
        } finally {
            \restore_error_handler();
        }
    }
}
