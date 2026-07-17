<?php

declare(strict_types=1);

namespace GuzzleHttp\Cookie;

use GuzzleHttp\NonSerializableTrait;
use GuzzleHttp\Psr7\DiagnosticValue;

/**
 * Persists non-session cookies using a JSON formatted file
 */
class FileCookieJar extends CookieJar
{
    use NonSerializableTrait;

    /**
     * @var string filename
     */
    private string $filename;

    /**
     * @var bool Control whether to persist session cookies or not.
     */
    private bool $storeSessionCookies;

    /**
     * @var bool Whether to save the cookie jar on destruction.
     *
     * Disabled by __wakeup() to prevent FileCookieJar from being used as a
     * PHP object injection file-write gadget when an application unserializes
     * attacker-controlled data.
     */
    private bool $autoSave = false;

    /**
     * Create a new FileCookieJar object
     *
     * @param string $cookieFile          File to store the cookie data
     * @param bool   $storeSessionCookies Set to true to store session cookies
     *                                    in the cookie jar.
     *
     * @throws \RuntimeException if the file cannot be loaded or is invalid
     */
    public function __construct(string $cookieFile, bool $storeSessionCookies = false)
    {
        parent::__construct();
        $this->filename = $cookieFile;
        $this->storeSessionCookies = $storeSessionCookies;

        if (\file_exists($cookieFile)) {
            $this->load($cookieFile);
        }

        $this->autoSave = true;
    }

    /**
     * Saves the file when shutting down
     */
    public function __destruct()
    {
        if ($this->autoSave) {
            $this->save($this->filename);
        }
    }

    /**
     * Disable automatic persistence after unserialization.
     */
    public function __wakeup(): void
    {
        $this->autoSave = false;
    }

    public function __unserialize(array $data): void
    {
        $this->autoSave = false;

        throw new \LogicException(static::class.' should never be unserialized');
    }

    /**
     * Saves the cookies to a file.
     *
     * @param string $filename File to save
     *
     * @throws \RuntimeException if the cookie data cannot be encoded or the
     *                           file cannot be written
     */
    public function save(string $filename): void
    {
        $json = [];
        /** @var SetCookie $cookie */
        foreach ($this as $cookie) {
            if (CookieJar::shouldPersist($cookie, $this->storeSessionCookies)) {
                $data = $cookie->toArray();
                $data['HostOnly'] = $cookie->getHostOnly();
                $json[] = $data;
            }
        }

        try {
            $jsonStr = \json_encode($json, \JSON_HEX_TAG | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Unable to encode cookie data', 0, $e);
        }

        if (false === \file_put_contents($filename, $jsonStr, \LOCK_EX)) {
            throw new \RuntimeException(\sprintf('Unable to save file %s', DiagnosticValue::escape($filename)));
        }

        // Best-effort: restrict the cookie file to the owner so persisted
        // cookies are not world-readable.
        @\chmod($filename, 0600);
    }

    /**
     * Load cookies from a JSON formatted file.
     *
     * Old cookies are kept unless overwritten by newly loaded ones.
     * Cookie records are constructed before any are passed to setCookie().
     *
     * @param string $filename Cookie file to load.
     *
     * @throws \RuntimeException if the file cannot be loaded or is invalid
     */
    public function load(string $filename): void
    {
        $json = \file_get_contents($filename);
        if (false === $json) {
            throw new \RuntimeException(\sprintf('Unable to load file %s', DiagnosticValue::escape($filename)));
        }
        if ($json === '') {
            return;
        }

        $message = \sprintf('Invalid cookie file: %s', DiagnosticValue::escape($filename));

        try {
            $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException($message, 0, $e);
        }

        // Associative decoding turns JSON objects into arrays, so inspect the root syntax too.
        if (!\is_array($data) || \substr($json, \strspn($json, " \t\n\r"), 1) !== '[') {
            throw new \RuntimeException($message);
        }

        $cookies = [];
        foreach ($data as $cookie) {
            if (!\is_array($cookie) || !\array_key_exists('HostOnly', $cookie) || !\is_bool($cookie['HostOnly'])) {
                throw new \RuntimeException($message);
            }

            try {
                $cookies[] = new SetCookie($cookie);
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException($message, 0, $e);
            }
        }

        foreach ($cookies as $cookie) {
            $this->setCookie($cookie);
        }
    }
}
