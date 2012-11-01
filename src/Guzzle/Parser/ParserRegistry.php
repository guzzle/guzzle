<?php

namespace Guzzle\Parser;

/**
 * Registry of parsers used by the application
 */
class ParserRegistry
{
    /**
     * @var ParserRegistry Singleton instance
     */
    protected static $instance;

    /**
     * @var array Array of parser instances
     */
    protected $instances = array();

    /**
     * @var array Mapping of parser name to default class
     */
    protected $mapping = array(
        'message'      => 'Guzzle\\Parser\\Message\\MessageParser',
        'cookie'       => 'Guzzle\\Parser\\Cookie\\CookieParser',
        'url'          => 'Guzzle\\Parser\\Url\\UrlParser',
        'uri_template' => 'Guzzle\\Parser\\UriTemplate\\UriTemplate',
    );

    /**
     * Get a singleton instance
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new static;
        }

        return self::$instance;
    }

    /**
     * Constructor used to apply the most performant parsers based on loaded extensions
     */
    public function __construct()
    {
        // Use the PECL URI template parser if available
        if (extension_loaded('uri_template')) {
            $this->mapping['uri_template'] = 'Guzzle\\Parser\\UriTemplate\\PeclUriTemplate';
        }
    }

    /**
     * Get a parser by name from an instance
     *
     * @param string $name Name of the parser to retrieve
     *
     * @return mixed|null
     */
    public function getParser($name)
    {
        if (!isset($this->instances[$name])) {
            if (!isset($this->mapping[$name])) {
                return null;
            }
            $class = $this->mapping[$name];
            $this->instances[$name] = new $class();
        }

        return $this->instances[$name];
    }

    /**
     * Register a custom parser by name with the register
     *
     * @param string $name   Name or handle of the parser to register
     * @param mixed  $parser Instantiated parser to register
     */
    public function registerParser($name, $parser)
    {
        $this->instances[$name] = $parser;
    }

    /**
     * Get a specific parser by handle name
     *
     * @param string $name Name of the parser to retrieve
     *
     * @return mixed|null Returns null if the parser is not found or cannot be instantiated
     * @deprecated Will be removed in 3.1.0
     * @codeCoverageIgnore
     */
    public static function get($name)
    {
        return self::getInstance()->getParser($name);
    }

    /**
     * Register a custom parser by name with the register
     *
     * @param      string $name   Name or handle of the parser to register
     * @param      mixed  $parser Instantiated parser to register
     * @deprecated Will be removed in 3.1.0
     * @codeCoverageIgnore
     */
    public static function set($name, $parser)
    {
        self::getInstance()->registerParser($name, $parser);
    }
}
