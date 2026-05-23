<?php

declare(strict_types=1);

if (!\defined('IDNA_DEFAULT')) {
    \define('IDNA_DEFAULT', 0);
}

if (!\defined('INTL_IDNA_VARIANT_UTS46')) {
    \define('INTL_IDNA_VARIANT_UTS46', 1);
}

if (!\class_exists('CurlShareHandle')) {
    class CurlShareHandle
    {
    }
}

if (!\class_exists('CurlSharePersistentHandle')) {
    class CurlSharePersistentHandle
    {
    }
}

if (!\function_exists('curl_share_init_persistent')) {
    function curl_share_init_persistent(array $share_options): CurlSharePersistentHandle
    {
        return new CurlSharePersistentHandle();
    }
}
