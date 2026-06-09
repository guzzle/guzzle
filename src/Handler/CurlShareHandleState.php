<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Exception\InvalidArgumentException;
use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\TransportSharing;

/**
 * @internal
 */
final class CurlShareHandleState
{
    use NonSerializableTrait;

    /**
     * @var resource|\CurlShareHandle|\CurlSharePersistentHandle|null
     */
    public $handle;

    public string $mode;

    /**
     * @param resource|\CurlShareHandle|\CurlSharePersistentHandle|null $handle
     */
    private function __construct(string $mode, $handle)
    {
        $this->mode = $mode;
        $this->handle = $handle;
    }

    /**
     * @param mixed $sharing
     */
    public static function fromOption($sharing): ?self
    {
        if ($sharing instanceof self) {
            return $sharing;
        }

        $mode = self::normalizeMode($sharing, 'transport_sharing');
        if ($mode === TransportSharing::NONE) {
            return null;
        }

        if ($mode === TransportSharing::HANDLER_PREFER) {
            return self::createHandlerShareOrNull($mode);
        }

        if ($mode === TransportSharing::HANDLER_REQUIRE) {
            return self::createHandlerShare($mode);
        }

        if ($mode === TransportSharing::PERSISTENT_PREFER) {
            return self::createPersistentShareOrFallback();
        }

        return self::createPersistentShare($mode);
    }

    /**
     * @param mixed $sharing
     */
    public static function normalizeMode($sharing, string $option): string
    {
        if ($sharing instanceof self) {
            return $sharing->mode;
        }

        if ($sharing === null || $sharing === TransportSharing::NONE) {
            return TransportSharing::NONE;
        }

        if (
            $sharing === TransportSharing::HANDLER_PREFER
            || $sharing === TransportSharing::HANDLER_REQUIRE
            || $sharing === TransportSharing::PERSISTENT_PREFER
            || $sharing === TransportSharing::PERSISTENT_REQUIRE
        ) {
            return $sharing;
        }

        throw new InvalidArgumentException(\sprintf(
            'The "%s" option must be null or a GuzzleHttp\\TransportSharing::* constant; received %s.',
            $option,
            \get_debug_type($sharing)
        ));
    }

    public static function assertNoRequiredSharingCustomFactoryConflict(array $options, string $handlerName): void
    {
        if (!\array_key_exists('handle_factory', $options) || $options['handle_factory'] === null) {
            return;
        }

        $mode = self::normalizeMode($options['transport_sharing'] ?? null, 'transport_sharing');
        if (!\in_array($mode, [TransportSharing::HANDLER_REQUIRE, TransportSharing::PERSISTENT_REQUIRE], true)) {
            return;
        }

        throw new InvalidArgumentException(\sprintf(
            'The "transport_sharing" %s option cannot require sharing with a custom "handle_factory" because Guzzle cannot ensure that the custom factory applies CURLOPT_SHARE.',
            $handlerName
        ));
    }

    private static function createHandlerShareOrNull(string $mode): ?self
    {
        try {
            return self::createHandlerShare($mode);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function createHandlerShare(string $mode): self
    {
        if (!\function_exists('curl_share_init') || !\function_exists('curl_share_setopt')) {
            throw new InvalidArgumentException('The "transport_sharing" option requires cURL share support.');
        }

        self::requireCurlConstant('CURLOPT_SHARE');
        $shareOption = self::requireCurlConstant('CURLSHOPT_SHARE');
        $locks = self::handlerLocks($mode);
        $handle = curl_share_init();

        try {
            foreach ($locks as $lock) {
                try {
                    $success = curl_share_setopt($handle, $shareOption, $lock);
                } catch (\Throwable $e) {
                    throw new InvalidArgumentException('Unable to configure cURL share handle: '.$e->getMessage(), 0, $e);
                }

                if (!$success) {
                    throw new InvalidArgumentException(\sprintf('Unable to configure cURL share handle with lock data %d.', $lock));
                }
            }
        } catch (\Throwable $e) {
            self::closeHandlerShareHandleOnPhp7($handle);

            throw $e;
        }

        return new self($mode, $handle);
    }

    private static function createPersistentShareOrFallback(): ?self
    {
        if (self::supportsPersistentShare()) {
            try {
                return self::createPersistentShare(TransportSharing::PERSISTENT_PREFER);
            } catch (\Throwable $e) {
                // Fall back to handler-lifetime best effort below.
            }
        }

        return self::createHandlerShareOrNull(TransportSharing::HANDLER_PREFER);
    }

    private static function createPersistentShare(string $mode): self
    {
        CurlVersion::ensureConnectionSharingSupported();
        CurlVersion::ensureSslSessionSharingSupported();

        if (!self::supportsPersistentShare()) {
            throw new InvalidArgumentException('The "transport_sharing" option requires persistent cURL share handle support.');
        }

        self::requireCurlConstant('CURLOPT_SHARE');

        try {
            $handle = curl_share_init_persistent(self::persistentLocks());
        } catch (\Throwable $e) {
            throw new InvalidArgumentException(
                'Unable to create persistent cURL share handle: '.$e->getMessage(),
                0,
                $e
            );
        }

        return new self($mode, $handle);
    }

    private static function supportsPersistentShare(): bool
    {
        return CurlVersion::supportsConnectionSharing()
            && CurlVersion::supportsSslSessionSharing()
            && \function_exists('curl_share_init_persistent')
            && \class_exists('CurlSharePersistentHandle')
            && \defined('CURL_LOCK_DATA_DNS')
            && \defined('CURL_LOCK_DATA_CONNECT')
            && \defined('CURL_LOCK_DATA_SSL_SESSION');
    }

    /**
     * @return int[]
     */
    private static function handlerLocks(string $mode): array
    {
        CurlVersion::ensureHandlerSharingSupported();

        if ($mode === TransportSharing::HANDLER_REQUIRE) {
            CurlVersion::ensureSslSessionSharingSupported();
        }

        $locks = [
            self::requireCurlConstant('CURL_LOCK_DATA_DNS'),
        ];

        if (CurlVersion::supportsSslSessionSharing()) {
            $locks[] = self::requireCurlConstant('CURL_LOCK_DATA_SSL_SESSION');
        }

        return $locks;
    }

    /**
     * @return int[]
     */
    private static function persistentLocks(): array
    {
        return [
            self::requireCurlConstant('CURL_LOCK_DATA_DNS'),
            self::requireCurlConstant('CURL_LOCK_DATA_CONNECT'),
            self::requireCurlConstant('CURL_LOCK_DATA_SSL_SESSION'),
        ];
    }

    private static function requireCurlConstant(string $constant): int
    {
        if (!\defined($constant)) {
            throw new InvalidArgumentException(\sprintf(
                'The "transport_sharing" option requires %s, but it is not available in the installed PHP cURL extension.',
                $constant
            ));
        }

        $value = \constant($constant);
        if (!\is_int($value)) {
            throw new InvalidArgumentException(\sprintf('The cURL constant %s must resolve to an integer.', $constant));
        }

        return $value;
    }

    /**
     * @param resource|\CurlShareHandle $handle
     */
    private static function closeHandlerShareHandleOnPhp7($handle): void
    {
        if (\PHP_VERSION_ID < 80000 && \is_resource($handle)) {
            curl_share_close($handle);
        }
    }
}
