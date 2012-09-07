<?php

namespace Guzzle\Service;

use Guzzle\Service\Exception\JsonException;

/**
 * Used to load JSON files that have an "includes" array.  Each file in the
 * array is then loaded and merged into the configuration.
 */
class JsonLoader
{
    /**
     * @var array Aliases that will be replaces by an actual filename
     */
    protected $aliases = array();

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
     * Loads a JSON file and includes any files in the "includes" array
     *
     * @param string $jsonFile File to load
     *
     * @return array
     * @throws JsonException if unable to open the file or if there is a parsing error
     */
    public function parseJsonFile($jsonFile)
    {
        // Use the registered alias if one matches the file
        if (isset($this->aliases[$jsonFile])) {
            $jsonFile = $this->aliases[$jsonFile];
        }

        // Ensure that the file can be opened for reading
        if (!is_readable($jsonFile)) {
            throw new JsonException("Unable to open {$jsonFile} for reading");
        }

        $data = json_decode(file_get_contents($jsonFile), true);
        // Throw an exception if there was an error loading the file
        if ($error = json_last_error()) {
            throw new JsonException("Error loading JSON data from {$jsonFile}: {$error}");
        }

        // Handle includes
        if (!empty($data['includes'])) {
            foreach ($data['includes'] as $path) {
                if ($path[0] != DIRECTORY_SEPARATOR && !isset($this->aliases[$path])) {
                    $path = dirname($jsonFile) . DIRECTORY_SEPARATOR . $path;
                }
                $data = $this->mergeJson($this->parseJsonFile($path), $data);
            }
        }

        return $data;
    }

    /**
     * Default implementation for merging two JSON files (uses array_merge_recursive)
     *
     * @param array $a Original JSON data
     * @param array $b JSON data to merge into the original and overwrite existing values
     *
     * @return array
     */
    protected function mergeJson(array $a, array $b)
    {
        return array_merge_recursive($a, $b);
    }
}
