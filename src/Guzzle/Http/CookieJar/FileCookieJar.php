<?php

namespace Guzzle\Http\CookieJar;

use Guzzle\Common\Exception\RuntimeException;

/**
 * Persists cookies using a JSON formatted file
 */
class FileCookieJar extends ArrayCookieJar
{
    /**
     * @var string filename
     */
    protected $filename;

    /**
     * Create a new FileCookieJar object
     *
     * @param string $cookieFile File to store the cookie data
     *
     * @throws RuntimeException if the file cannot be found or created
     */
    public function __construct($cookieFile)
    {
        $this->filename = $cookieFile;
        $this->load();
    }

    /**
     * Saves the file when shutting down
     */
    public function __destruct()
    {
        $this->persist();
    }

    /**
     * Save the contents of the data array to the file
     *
     * @throws RuntimeException if the file cannot be found or created
     */
    protected function persist()
    {
        if (false === file_put_contents($this->filename, json_encode($this->getCookies(null, null, null, true, true)))) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Unable to open file ' . $this->filename);
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * Load the contents of the json formatted file into the data array and
     * discard the unsaved state of the jar object
     */
    protected function load()
    {
        $json = file_get_contents($this->filename);
        if (false === $json) {
            // @codeCoverageIgnoreStart
            throw new RuntimeException('Unable to open file ' . $this->filename);
            // @codeCoverageIgnoreEnd
        }

        $this->cookies = $json ? json_decode($json, true) : array();
    }
}
