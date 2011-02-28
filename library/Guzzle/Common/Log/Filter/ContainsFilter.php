<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Log\Filter;

use Guzzle\Common\Filter\AbstractFilter;
use Guzzle\Common\Log\LogException;

/**
 * Filters log messages based on the existence of a substring within the log
 * message or a regexp match on the log message
 *
 * This filter must have a 'match' parameter passed through its constructor in
 * order to process messages correctly.  The match parameter can be a simple
 * string or a regular expression (a string surrounded by forward slashses
 * '/X/').  The match parameter can be a string, a regular expression, or an
 * array of strings and or regular expressions.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ContainsFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     *
     * @throws LogFilterException
     */
    protected function init()
    {
        if (!$this->get('match')) {
            throw new LogException(
                'A match value must be specified on a contains log filter'
            );
        }
    }

    /**
     * Check if a message is value
     *
     * @param string $message Message value to check
     * @param string $contains Check if the message contains this value
     *
     * @return bool
     */
    private function isValid($message, $contains)
    {
        return ($this->isRegex($contains))
            ? preg_match($contains, $message)
            : stripos($message, $contains) !== false;
    }

    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        $string = $this->get('match');

        if (!is_array($string)) {
            return $this->isValid($command['message'], $string);
        } else {
            foreach ($string as $s) {
                if ($this->isValid($command['message'], $s)) {
                    return true;
                }
            }
        }

        return false;
    }
}