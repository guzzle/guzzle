<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Log\Filter;

use Guzzle\Common\Log\Logger;
use Guzzle\Common\Log\Adapter\LogAdapterInterface;
use Guzzle\Common\Log\Adapter\ZendLogAdapter;

/**
 * Abstract filter test class
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractFilterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Zend_Log
     */
    protected $zendLog;

    /**
     * @var LogAdapterInterface
     */
    protected $adapter;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    public function __construct()
    {
        // Make sure that the Zend Framework is in the PHP include path
        if (!class_exists('Zend_Log')) {
            $this->markTestSkipped(
                'The Zend Framework is not present in your path'
            );
            return;
        }

        $this->logger = new Logger();

        // Create Zend Framework log objects with a log writer to a std out
        $this->zendLog = new \Zend_Log(new \Zend_Log_Writer_Stream('php://output'));

        // Create a new log adapter and log to the php standard out
        // Not using any minimum priority as we aren't testing adapters in this
        // test case
        $this->adapter = new ZendLogAdapter($this->zendLog);

        $this->logger->addAdapter($this->adapter);

        parent::__construct();
    }
}