<?php

namespace Guzzle\Parser;

/**
 * Registry of parsers used by the application
 */
class ParserRegistry
{
    /**
     * @var array Array of parser instances
     */
    protected static $instances = array();

    /**
     * @var array Mapping of parser name to default class
     */
    protected static $mapping = array(
        'message'      => 'Guzzle\\Parser\\Message\\MessageParser',
        'cookie'       => 'Guzzle\\Parser\\Cookie\\CookieParser',
        'url'          => 'Guzzle\\Parser\\Url\\UrlParser',
        'uri_template' => 'Guzzle\\Parser\\UriTemplate\\UriTemplate',
    );

    /**
     * Get a specific parser by handle name
     *
     * @param string $name Name of the parser to retrieve
     *
     * @return mixed|null Returns null if the parser is not found or cannot be instantiated
     */
    public static function get($name)
    {
        if (!isset(self::$instances[$name])) {
            if (!isset(self::$mapping[$name])) {
                return null;
            }
            $class = self::$mapping[$name];
            self::$instances[$name] = new $class();
        }

        return self::$instances[$name];
    }

    /**
     * Register a custom parser by name with the register
     *
     * @param string $name   Name or handle of the parser to register
     * @param mixed  $parser Instantiated parser to register
     */
    public static function set($name, $parser)
    {
        self::$instances[$name] = $parser;
    }
}
