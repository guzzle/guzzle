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

    if (!\class_exists('WeakMap')) {
        /**
         * @template TKey of object
         * @template TValue
         *
         * @implements ArrayAccess<TKey, TValue>
         */
        final class WeakMap implements ArrayAccess
        {
            /**
             * @param TKey $offset
             */
            public function offsetExists($offset): bool
            {
                return false;
            }

            /**
             * @param TKey $offset
             *
             * @return TValue
             */
            public function offsetGet($offset)
            {
                throw new LogicException('WeakMap requires PHP 8.0 or newer.');
            }

            /**
             * @param TKey   $offset
             * @param TValue $value
             */
            public function offsetSet($offset, $value): void
            {
            }

            /**
             * @param TKey $offset
             */
            public function offsetUnset($offset): void
            {
            }
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
