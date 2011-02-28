<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Plugin\Cookie\CookieJar;

use Guzzle\Common\Collection;
use Guzzle\Http\Plugin\PluginException;

/**
 * Persists cookies using a JSON formatted file
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class FileCookieJar extends ArrayCookieJar
{
    /**
     * @var string filename
     */
    protected $file;

    /**
     * Create a new FileCookieJar object
     *
     * @param string $cookieFile File to store the cookie data
     *
     * @throws PluginException if the file cannot be found or created
     */
    public function __construct($cookieFile)
    {
        $this->file = $cookieFile;
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
     * @throws PluginException if the file cannot be found or created
     */
    protected function persist()
    {
        $handle = fopen($this->file, 'w+');
        // @codeCoverageIgnoreStart
        if ($handle === false) {
            throw new PluginException('Unable to open file ' . $this->file);
        }
        // @codeCoverageIgnoreEnd
        
        fwrite($handle, json_encode($this->cookies));
        fclose($handle);
    }

    /**
     * Load the contents of the json formatted file into the data array and
     * discard the unsaved state of the jar object
     */
    protected function load()
    {
        $handle = fopen($this->file, 'c+');
        // @codeCoverageIgnoreStart
        if ($handle === false) {
            throw new PluginException('Unable to open file ' . $this->file);
        }
        // @codeCoverageIgnoreEnd

        $json = '';
        while ($data = fread($handle, 8096)) {
            $json .= $data;
        }

        fclose($handle);
        
        $this->cookies = ($json) ? json_decode($json, true) : array();
    }
}