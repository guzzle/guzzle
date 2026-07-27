<?php

declare(strict_types=1);

namespace {
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
}

namespace Openssl {
    if (!\class_exists(Session::class)) {
        class Session
        {
            public function isResumable(): bool
            {
                return false;
            }

            public function getProtocol(): ?string
            {
                return null;
            }

            public function getCreatedAt(): int
            {
                return 0;
            }

            public function getTimeout(): int
            {
                return 0;
            }

            public function hasTicket(): bool
            {
                return false;
            }

            public function getTicketLifetimeHint(): ?int
            {
                return null;
            }
        }
    }
}
