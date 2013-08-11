<?php

namespace Guzzle\Http\Header;

/**
 * Provides an easy to use Cookie abstraction
 */
class Cookie extends DefaultHeader
{
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
        // Quote the value if it is not already and contains problematic characters
        if (substr($value, 0, 1) !== '"' && substr($value, -1, 1) !== '"' && strpbrk($value, ';,')) {
            $value = '"' . $value . '"';
        }

        $val = "{$name}={$value}";
        $this->values = [isset($this->values[0]) ? "{$this->values[0]}; {$val}": $val];

        return $this;
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
        $this->values = [''];
        foreach ($values as $key => $value) {
            $this->values[0] .= "{$key}={$value}; ";
        }
        $this->values[0] = rtrim($this->values[0], '; ');

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
        return $this->parseParams()[0];
    }
}
