<?php
namespace GuzzleHttp\Cookie;

/**
 * Persists cookies in the client session
 */
class SessionCookieJar extends CookieJar
{
    /** @var string session key */
    private $sessionKey;

    /**
     * Create a new SessionCookieJar object
     *
     * @param string $sessionKey Session key name to store the cookie data in session
     */
    public function __construct($sessionKey)
    {
        $this->sessionKey = $sessionKey;
        $this->load();
    }

    /**
     * Saves cookies to session when shutting down
     */
    public function __destruct()
    {
        $this->save();
    }

    /**
     * Save cookies to the client session
     */
    public function save()
    {
        $json = [];
        foreach ($this as $cookie) {
            /** @var SetCookie $cookie */
            if ($cookie->getExpires() && !$cookie->getDiscard()) {
                $json[] = $cookie->toArray();
            }
        }

        $_SESSION[$this->sessionKey] = json_encode($json);
    }

    /**
     * Load the contents of the client session into the data array
     */
    protected function load()
    {
        $cookieJar = isset($_SESSION[$this->sessionKey])
            ? $_SESSION[$this->sessionKey]
            : null;

        $data = json_decode($cookieJar, true);
        if (is_array($data)) {
            foreach ($data as $cookie) {
                $this->setCookie(new SetCookie($cookie));
            }
        } elseif (strlen($data)) {
            throw new \RuntimeException("Invalid cookie data");
        }
    }
}
