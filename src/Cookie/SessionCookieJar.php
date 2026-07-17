<?php

declare(strict_types=1);

namespace GuzzleHttp\Cookie;

use GuzzleHttp\NonSerializableTrait;

/**
 * Persists cookies in the client session
 */
class SessionCookieJar extends CookieJar
{
    use NonSerializableTrait;

    /**
     * @var string session key
     */
    private string $sessionKey;

    /**
     * @var bool Control whether to persist session cookies or not.
     */
    private bool $storeSessionCookies;

    /**
     * @var bool Whether to save the cookie jar on destruction.
     *
     * Disabled by __wakeup() to prevent SessionCookieJar from being used as a
     * PHP object injection $_SESSION-write gadget when an application
     * unserializes attacker-controlled data.
     */
    private bool $autoSave = false;

    /**
     * Create a new SessionCookieJar object
     *
     * @param string $sessionKey          Session key name to store the cookie
     *                                    data in session
     * @param bool   $storeSessionCookies Set to true to store session cookies
     *                                    in the cookie jar.
     *
     * @throws \RuntimeException if the session contains invalid cookie data
     */
    public function __construct(string $sessionKey, bool $storeSessionCookies = false)
    {
        parent::__construct();
        $this->sessionKey = $sessionKey;
        $this->storeSessionCookies = $storeSessionCookies;
        $this->load();
        $this->autoSave = true;
    }

    /**
     * Saves cookies to session when shutting down
     */
    public function __destruct()
    {
        if ($this->autoSave) {
            $this->save();
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
     * Save cookies to the client session.
     *
     * @throws \RuntimeException if the cookie data cannot be encoded
     */
    public function save(): void
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
            $json = \json_encode($json, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Unable to encode cookie data', 0, $e);
        }

        $_SESSION[$this->sessionKey] = $json;
    }

    /**
     * Load cookies from the client session.
     *
     * @throws \RuntimeException if the session contains invalid cookie data
     */
    protected function load(): void
    {
        if (!isset($_SESSION[$this->sessionKey])) {
            return;
        }

        $message = 'Invalid cookie data';
        $json = $_SESSION[$this->sessionKey];
        if (!\is_string($json)) {
            throw new \RuntimeException($message);
        }

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
