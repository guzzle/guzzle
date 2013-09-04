<?php

namespace Guzzle\Http\Header;

/**
 * Default header factory implementation
 */
class HeaderFactory implements HeaderFactoryInterface
{
    /** @var self */
    private static $instance;

    /**
     * Get an instance of the default header factory
     *
     * @return self
     */
    public static function getDefaultFactory()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function createHeader($header, $value = null)
    {
        return new DefaultHeader($header, $value);
    }
}
