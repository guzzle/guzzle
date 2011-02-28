<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Log\Mock;

/**
 * Mock LogFormatter object
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class MockLogFormatter extends \Guzzle\Common\Log\Formatter\AbstractFormatter
{
    /**
     * {@inheritdoc}
     */
    protected $className = 'Guzzle\Tests\Common\Mock\MockSubject';
    
    protected $retVal = array(
        'priority' => \LOG_INFO,
        'message' => 'test'
    );

    public function setClassName($name)
    {
        $this->className = $name;
    }

    public function setReturnValue($value)
    {
        $this->retVal = $value;
    }

    /**
     * Mock method to format data into a string that can be logged.  Always
     * returns a priority of INFO and message containing test
     *
     * @param mixed $data
     *      The data to format
     *
     * @param integer $verbosity (optional)
     *      The level of detail for logging.
     *
     * @return array|null
     *      Returns an associative array containing two keys: 'priority' =>
     *      the priority of the message, and 'message' => the body of the
     *      message.
     */
    public function format($data, $verbosity = self::DETAIL_NORMAL)
    {
        return $this->retVal;
    }
}