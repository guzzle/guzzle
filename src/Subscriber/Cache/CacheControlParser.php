<?php

namespace GuzzleHttp\Subscriber\Cache;

use GuzzleHttp\Header\HeaderInterface;

class CacheControl
{
    /** @var array */
    private $directives;

    /** @var HeaderInterface */
    private $header;

    /**
     * @param HeaderInterface $header
     */
    public function __construct(HeaderInterface $header)
    {
        $this->header = $header;
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
     * Get an associative array of cache control directives
     *
     * @return array
     */
    public function getDirectives()
    {
        if ($this->directives === null) {
            $this->directives = [];
            foreach ($this->header->parseParams() as $collection) {
                foreach ($collection as $key => $value) {
                    $this->directives[$key] = $value === '' ? true : $value;
                }
            }
        }

        return $this->directives;
    }
}
