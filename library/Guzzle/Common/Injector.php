<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common;

use Guzzle\Common\Collection;

/**
 * Handles the injection of configuration variables into a string
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Injector
{
    /**
     * Inject configuration settings into an input string
     *
     * @param string $input Input to inject
     * @param Collection $config Configuration data to inject into the input
     *
     * @return string
     */
    public static function inject($input, Collection $config)
    {
        // Skip expensive regular expressions if it isn't needed
        if (strpos($input, '{{') === false) {
            return $input;
        }

        return preg_replace_callback('/{{\s*([A-Za-z_\-\.0-9]+)\s*}}/',
            function($matches) use ($config) {
                return $config->get(trim($matches[1]));
            }, $input
        );
    }
}