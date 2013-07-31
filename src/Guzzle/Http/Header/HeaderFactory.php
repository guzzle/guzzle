<?php

namespace Guzzle\Http\Header;

/**
 * Default header factory implementation
 */
class HeaderFactory implements HeaderFactoryInterface
{
    /** @var self */
    private static $instance;

    /** @var array */
    protected $mapping = array(
        'cache-control' => 'Guzzle\Http\Header\CacheControl',
        'link'          => 'Guzzle\Http\Header\Link',
    );

    /**
     * Get an instance of the default header factory
     *
     * @return self
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function createHeader($header, $value = null)
    {
        $lowercase = strtolower($header);

        return isset($this->mapping[$lowercase])
            ? new $this->mapping[$lowercase]($header, $value)
            : new DefaultHeader($header, $value);
    }
}
