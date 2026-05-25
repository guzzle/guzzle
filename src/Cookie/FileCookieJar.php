<?php

declare(strict_types=1);

namespace GuzzleHttp\Cookie;

use GuzzleHttp\Utils;

/**
 * Persists non-session cookies using a JSON formatted file
 */
class FileCookieJar extends CookieJar
{
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
    private bool $autoSave = true;

    /**
     * Create a new FileCookieJar object
     *
     * @param string $cookieFile          File to store the cookie data
     * @param bool   $storeSessionCookies Set to true to store session cookies
     *                                    in the cookie jar.
     *
     * @throws \RuntimeException if the file cannot be found or created
     */
    public function __construct(string $cookieFile, bool $storeSessionCookies = false)
    {
        parent::__construct();
        $this->filename = $cookieFile;
        $this->storeSessionCookies = $storeSessionCookies;

        if (\file_exists($cookieFile)) {
            $this->load($cookieFile);
        }
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

    /**
     * Saves the cookies to a file.
     *
     * @param string $filename File to save
     *
     * @throws \RuntimeException if the file cannot be found or created
     */
    public function save(string $filename): void
    {
        $json = [];
        /** @var SetCookie $cookie */
        foreach ($this as $cookie) {
            if (CookieJar::shouldPersist($cookie, $this->storeSessionCookies)) {
                $json[] = $cookie->toArray();
            }
        }

        $jsonStr = Utils::jsonEncode($json, \JSON_HEX_TAG);
        if (false === \file_put_contents($filename, $jsonStr, \LOCK_EX)) {
            throw new \RuntimeException("Unable to save file {$filename}");
        }
    }

    /**
     * Load cookies from a JSON formatted file.
     *
     * Old cookies are kept unless overwritten by newly loaded ones.
     *
     * @param string $filename Cookie file to load.
     *
     * @throws \RuntimeException if the file cannot be loaded.
     */
    public function load(string $filename): void
    {
        $json = \file_get_contents($filename);
        if (false === $json) {
            throw new \RuntimeException("Unable to load file {$filename}");
        }
        if ($json === '') {
            return;
        }

        $data = Utils::jsonDecode($json, true);
        if (\is_array($data)) {
            foreach ($data as $cookie) {
                if (!\is_array($cookie)) {
                    throw new \RuntimeException("Invalid cookie file: {$filename}");
                }

                try {
                    $this->setCookie(new SetCookie($cookie));
                } catch (\InvalidArgumentException $e) {
                    throw new \RuntimeException("Invalid cookie file: {$filename}", 0, $e);
                }
            }
        } elseif (\is_scalar($data) && !empty($data)) {
            throw new \RuntimeException("Invalid cookie file: {$filename}");
        }
    }
}
