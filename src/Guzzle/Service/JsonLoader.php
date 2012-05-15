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
     * Loads a JSON file and includes any files in the "includes" array
     *
     * @param string $jsonFile File to load
     *
     * @return array
     * @throws JsonException if unable to open the file or if there is a parsing error
     */
    public function parseJsonFile($jsonFile)
    {
        // Ensure that the file can be opened for reading
        if (!is_readable($jsonFile)) {
            throw new JsonException("Unable to open {$jsonFile} for reading");
        }

        $data = json_decode(file_get_contents($jsonFile), true);
        $error = json_last_error();

        // Throw an exception if there was an error loading the file
        if ($error) {
            throw new JsonException("Error loading JSON data from {$jsonFile}: {$error}");
        }

        // Handle includes
        if (!empty($data['includes'])) {
            foreach ($data['includes'] as $path) {
                if ($path[0] != DIRECTORY_SEPARATOR) {
                    $path = dirname($jsonFile) . DIRECTORY_SEPARATOR . $path;
                }
                $data = array_merge_recursive($this->parseJsonFile($path), $data);
            }
        }

        return $data;
    }
}
