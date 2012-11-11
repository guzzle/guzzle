<?php

namespace Guzzle\Service;

use Guzzle\Common\Exception\InvalidArgumentException;
use Guzzle\Common\Exception\RuntimeException;

/**
 * Abstract config loader
 */
abstract class AbstractConfigLoader implements ConfigLoaderInterface
{
    /**
     * @var array Array of aliases for actual filenames
     */
    protected $aliases = array();

    /**
     * {@inheritdoc}
     */
    public function load($config, array $options = array())
    {
        if (is_string($config)) {
            $config = $this->loadFile($config);
        } elseif (!is_array($config)) {
            throw new InvalidArgumentException('Unknown type passed to configuration loader: ' . gettype($config));
        } else {
            $this->mergeIncludes($config);
        }

        return $this->build($config, $options);
    }

    /**
     * Add an include alias to the loader
     *
     * @param string $filename Filename to alias (e.g. _foo)
     * @param string $alias    Actual file to use (e.g. /path/to/foo.json)
     *
     * @return self
     */
    public function addAlias($filename, $alias)
    {
        $this->aliases[$filename] = $alias;

        return $this;
    }

    /**
     * Remove an alias from the loader
     *
     * @param string $alias Alias to remove
     *
     * @return self
     */
    public function removeAlias($alias)
    {
        unset($this->aliases[$alias]);

        return $this;
    }

    /**
     * Perform the parsing of a config file and create the end result
     *
     * @param array $config  Configuration data
     * @param array $options Options to use when building
     *
     * @return mixed
     */
    protected abstract function build($config, array $options);

    /**
     * Load a configuration file (can load JSON or PHP files that return an array when included)
     *
     * @param string $filename File to load
     *
     * @return array
     * @throws InvalidArgumentException
     */
    protected function loadFile($filename)
    {
        if (isset($this->aliases[$filename])) {
            $filename = $this->aliases[$filename];
        }

        if (!is_readable($filename)) {
            throw new InvalidArgumentException("Unable to open {$filename} for reading");
        }

        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if ($ext == 'js' || $ext == 'json') {
            $config = json_decode(file_get_contents($filename), true);
            // Throw an exception if there was an error loading the file
            if ($error = json_last_error()) {
                throw new RuntimeException("Error loading JSON data from {$filename}: {$error}");
            }
        } elseif ($ext == 'php') {
            $config = require $filename;
            if (!is_array($config)) {
                throw new InvalidArgumentException('PHP files must return an array of configuration data');
            }
        } else {
            throw new InvalidArgumentException('Unknown file extension: ' . $filename);
        }

        // Merge include files into the configuration array
        $this->mergeIncludes($config, dirname($filename));

        return $config;
    }

    /**
     * Merges in all include files
     *
     * @param array  $config   Config data that contains includes
     * @param string $basePath Base path to use when a relative path is encountered
     *
     * @return array Returns the merged and included data
     */
    protected function mergeIncludes(&$config, $basePath = null)
    {
        if (!empty($config['includes'])) {
            foreach ($config['includes'] as &$path) {
                // Account for relative paths
                if ($path[0] != DIRECTORY_SEPARATOR && !isset($this->aliases[$path]) && $basePath) {
                    $path = "{$basePath}/{$path}";
                }
                $config = $this->mergeData($this->loadFile($path), $config);
            }
        }
    }

    /**
     * Default implementation for merging two arrays of data (uses array_merge_recursive)
     *
     * @param array $a Original data
     * @param array $b Data to merge into the original and overwrite existing values
     *
     * @return array
     */
    protected function mergeData(array $a, array $b)
    {
        return array_merge_recursive($a, $b);
    }
}
