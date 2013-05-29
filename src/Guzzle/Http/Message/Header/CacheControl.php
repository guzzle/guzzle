<?php

namespace Guzzle\Http\Message\Header;

use Guzzle\Http\Message\Header;

/**
 * Provides helpful functionality for Cache-Control headers
 */
class CacheControl extends Header
{
    /**
     * @var array
     */
    protected $directives;

    public function add($value)
    {
        parent::add($value);
        $this->directives = null;
    }

    public function removeValue($searchValue)
    {
        parent::removeValue($searchValue);
        $this->directives = null;
    }

    /**
     * Check if a specific cache control directive exists
     *
     * @param string $param Directive to retrieve
     *
     * @return bool
     */
    public function hasDirective($param)
    {
        $directives = $this->getDirectives();

        return isset($directives[$param]);
    }

    /**
     * Get a specific cache control directive
     *
     * @param string $param Directive to retrieve
     *
     * @return string|bool|null
     */
    public function getDirective($param)
    {
        $directives = $this->getDirectives();

        return isset($directives[$param]) ? $directives[$param] : null;
    }

    /**
     * Add a cache control directive
     *
     * @param string $param Directive to add
     * @param string $value Value to set
     *
     * @return self
     */
    public function addDirective($param, $value)
    {
        $directives = $this->getDirectives();
        $directives[$param] = $value;
        $this->updateFromDirectives($directives);

        return $this;
    }

    /**
     * Remove a cache control directive by name
     *
     * @param string $param Directive to remove
     *
     * @return self
     */
    public function removeDirective($param)
    {
        $directives = $this->getDirectives();
        unset($directives[$param]);
        $this->updateFromDirectives($directives);

        return $this;
    }

    /**
     * Get an associative array of cache control directives
     *
     * @return array
     */
    public function getDirectives()
    {
        if ($this->directives) {
            return $this->directives;
        }

        $this->directives = array();

        foreach ($this->parseParams() as $collection) {
            foreach ($collection as $key => $value) {
                $value = $value === '' ? true : $value;
                $this->directives[$key] = $value;
            }
        }

        return $this->directives;
    }

    /**
     * Updates the header value based on the parsed directives
     *
     * @param array $directives Array of cache control directives
     */
    protected function updateFromDirectives(array $directives)
    {
        $this->values = $this->directives = array();

        foreach ($directives as $key => $value) {
            if ($value === true) {
                $this->values[] = $key;
            } else {
                $this->values[] = "{$key}={$value}";
            }
        }
    }
}
