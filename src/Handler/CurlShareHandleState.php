<?php

declare(strict_types=1);

namespace GuzzleHttp\Handler;

use GuzzleHttp\Utils;

/**
 * @internal
 */
final class CurlShareHandleState
{
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
     * @param mixed $share
     */
    public static function fromOption($share): ?self
    {
        if ($share instanceof self) {
            return $share;
        }

        $mode = self::normalizeMode($share, 'share');
        if ($mode === CurlShare::NONE) {
            return null;
        }

        if ($mode === CurlShare::HANDLER) {
            return self::createHandlerShare($mode);
        }

        if ($mode === CurlShare::PERSISTENT_PREFER) {
            return self::createPersistentShareOrFallback();
        }

        return self::createPersistentShare($mode);
    }

    /**
     * @param mixed $share
     */
    public static function normalizeMode($share, string $option): string
    {
        if ($share instanceof self) {
            return $share->mode;
        }

        if ($share === null || $share === CurlShare::NONE) {
            return CurlShare::NONE;
        }

        if (
            $share === CurlShare::HANDLER
            || $share === CurlShare::PERSISTENT_PREFER
            || $share === CurlShare::PERSISTENT_REQUIRE
        ) {
            return $share;
        }

        throw new \InvalidArgumentException(\sprintf(
            'The "%s" option must be null or a GuzzleHttp\\Handler\\CurlShare::* constant; received %s.',
            $option,
            Utils::describeType($share)
        ));
    }

    public static function assertNoCustomFactoryConflict(array $options, string $handlerName): void
    {
        if (
            !\array_key_exists('handle_factory', $options)
            || $options['handle_factory'] === null
        ) {
            return;
        }

        $mode = self::normalizeMode($options['share'] ?? null, 'share');
        if ($mode === CurlShare::NONE) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'The "share" %s option cannot be used with a custom "handle_factory" because Guzzle cannot ensure that the custom factory applies CURLOPT_SHARE.',
            $handlerName
        ));
    }

    private static function createHandlerShare(string $mode): self
    {
        if (!\function_exists('curl_share_init') || !\function_exists('curl_share_setopt')) {
            throw new \InvalidArgumentException('The cURL handler option "share" requires cURL share support.');
        }

        self::requireCurlConstant('CURLOPT_SHARE');
        $shareOption = self::requireCurlConstant('CURLSHOPT_SHARE');
        $locks = self::handlerLocks();
        $handle = curl_share_init();

        try {
            foreach ($locks as $lock) {
                try {
                    $success = curl_share_setopt($handle, $shareOption, $lock);
                } catch (\Throwable $e) {
                    throw new \InvalidArgumentException('Unable to configure cURL share handle: '.$e->getMessage(), 0, $e);
                }

                if (!$success) {
                    throw new \InvalidArgumentException(\sprintf('Unable to configure cURL share handle with lock data %d.', $lock));
                }
            }
        } catch (\Throwable $e) {
            self::closeHandlerShareHandleOnPhp7($handle);

            throw $e;
        }

        return new self($mode, $handle);
    }

    private static function createPersistentShareOrFallback(): self
    {
        if (!self::supportsPersistentShare()) {
            return self::createHandlerShare(CurlShare::HANDLER);
        }

        try {
            return self::createPersistentShare(CurlShare::PERSISTENT_PREFER);
        } catch (\Throwable $e) {
            return self::createHandlerShare(CurlShare::HANDLER);
        }
    }

    private static function createPersistentShare(string $mode): self
    {
        if (!self::supportsPersistentShare()) {
            throw new \InvalidArgumentException('The cURL handler option "share" requires persistent cURL share handle support.');
        }

        self::requireCurlConstant('CURLOPT_SHARE');

        try {
            $handle = curl_share_init_persistent(self::persistentLocks());
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                'Unable to create persistent cURL share handle: '.$e->getMessage(),
                0,
                $e
            );
        }

        return new self($mode, $handle);
    }

    private static function supportsPersistentShare(): bool
    {
        return \function_exists('curl_share_init_persistent')
            && \class_exists('CurlSharePersistentHandle')
            && \defined('CURL_LOCK_DATA_DNS')
            && \defined('CURL_LOCK_DATA_CONNECT')
            && \defined('CURL_LOCK_DATA_SSL_SESSION');
    }

    /**
     * @return int[]
     */
    private static function handlerLocks(): array
    {
        return [
            self::requireCurlConstant('CURL_LOCK_DATA_DNS'),
            self::requireCurlConstant('CURL_LOCK_DATA_SSL_SESSION'),
        ];
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
            throw new \InvalidArgumentException(\sprintf(
                'The cURL handler option "share" requires %s, but it is not available in the installed PHP cURL extension.',
                $constant
            ));
        }

        $value = \constant($constant);
        if (!\is_int($value)) {
            throw new \InvalidArgumentException(\sprintf('The cURL constant %s must resolve to an integer.', $constant));
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
