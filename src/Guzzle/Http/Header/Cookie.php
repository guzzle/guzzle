<?php

namespace Guzzle\Http\Header;

/**
 * Provides an easy to use Cookie abstraction
 */
class Cookie extends DefaultHeader
{
    public function __construct($name, $values = array())
    {
        parent::__construct($name, $values, ';');
    }

    /**
     * Add a cookie
     *
     * @param string $name  Name of the value to set
     * @param string $value Value to set
     *
     * @return self
     */
    public function addCookie($name, $value)
    {
        return $this->add("{$name}={$value}");
    }

    /**
     * Check if a specific cookie name exists
     *
     * @param string $name Cookie name to check
     *
     * @return bool
     */
    public function hasCookie($name)
    {
        return isset($this->getCookies()[$name]);
    }

    /**
     * Remove a specific cookie by name
     *
     * @param string $name Cookie to remove
     *
     * @return self
     */
    public function removeCookie($name)
    {
        $values = $this->getCookies();
        unset($values[$name]);
        $this->values = [];
        foreach ($values as $k => $v) {
            $this->values[] = $k . '=' . $v;
        }

        return $this;
    }

    /**
     * Get a cookie value
     *
     * @param string $name Name of the cookie to retrieve
     *
     * @return null|string
     */
    public function getCookie($name)
    {
        $values = $this->getCookies();

        return isset($values[$name]) ? $values[$name] : null;
    }

    /**
     * Get all cookies as an associative array
     *
     * @return array
     */
    public function getCookies()
    {
        $values = [];
        foreach ($this->toArray() as $value) {
            $parts = explode('=', $value);
            $values[$parts[0]] = isset($parts[1]) ? $parts[1] : null;
        }

        return $values;
    }
}
