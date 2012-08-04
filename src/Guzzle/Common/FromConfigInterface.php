<?php

namespace Guzzle\Common;

/**
 * Classes that implement this interface have a factory method that is used
 * to instantiate the class from an array of configuration options.
 */
interface FromConfigInterface
{
    /**
     * Static factory method used to turn an array or collection of
     * configuration data into an instantiated object.  Any class
     * that implements this method can be instantiated via a
     * {@see Guzzle\Service\Builder\ServiceBuilderInterface}.
     *
     * @param array|Collection $config Configuration data
     *
     * @return FromConfigInterface
     */
    public static function factory($config = array());
}
