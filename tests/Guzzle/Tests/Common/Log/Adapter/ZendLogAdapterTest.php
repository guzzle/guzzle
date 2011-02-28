<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Log\Adapter;

use Guzzle\Common\Log\Adapter\LogAdapterInterface;
use Guzzle\Common\Log\Adapter\ZendLogAdapter;
use Guzzle\Common\Collection;
use Guzzle\Common\Filter\Chain;

/**
 * Test class for ZendLogAdapter
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ZendLogAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var ZendLogAdapter
     */
    protected $adapter;

    /**
     * @var Zend_Log
     */
    protected $log;

    /**
     * Sets up the fixture, for example, opens a network connection.
     * This method is called before a test is executed.
     */
    protected function setUp()
    {        
        $this->log = new \Zend_Log(new \Zend_Log_Writer_Stream('php://output'));
        $this->adapter = new ZendLogAdapter($this->log);
    }

    /**
     * Check for the existence of the Zend_Framework in your path
     */
    protected function zfSkip()
    {
        if (!class_exists('\Zend_Log')) {
            $this->markTestSkipped(
                'The Zend Framework is not present in your path'
            );
            return;
        }
    }

    /**
     * @covers Guzzle\Common\Log\Adapter\AbstractLogAdapter::__construct
     * @expectedException Guzzle\Common\Log\Adapter\LogAdapterException
     */
    public function testConstruct()
    {
        $this->zfSkip();
        
        $chain = new Chain();

        // A successful construction
        $this->adapter = new ZendLogAdapter($this->log, new Collection(), $chain);
        $this->assertEquals($chain, $this->adapter->getFilterChain());

        // Throws an exception
        $this->adapter = new ZendLogAdapter(new \stdClass());
    }

    /**
     * @covers Guzzle\Common\Log\Adapter\AbstractLogAdapter::log
     * @covers Guzzle\Common\Log\Adapter\ZendLogAdapter::logMessage
     * @outputBuffering enabled
     */
    public function testLog()
    {
        $this->zfSkip();
        
        // Test without a priority
        $this->adapter->log('test', \LOG_NOTICE, 'guzzle.common.log.adapter.zend_log_adapter', 'localhost');
        $this->assertEquals(1, substr_count(ob_get_contents(), 'test'));

        // Test with a priority
        $this->adapter->log('test', \LOG_ALERT);
        $this->assertEquals(2, substr_count(ob_get_contents(), 'test'));
    }

    /**
     * @covers Guzzle\Common\Log\Adapter\AbstractLogAdapter::getLogObject
     */
    public function testGetLogObject()
    {
        $this->zfSkip();
        
        $this->assertEquals($this->log, $this->adapter->getLogObject());
    }

    /**
     * @covers Guzzle\Common\Log\Adapter\AbstractLogAdapter::__call
     * @expectedException Zend_Log_Exception
     */
    public function testAdapterMustProxyToWrappedObject()
    {
        $this->zfSkip();
        
        $this->adapter->addPriority('EMERG', 0);
    }

    /**
     * @covers Guzzle\Common\Log\Adapter\AbstractLogAdapter::__call
     * @expectedException BadMethodCallException
     */
    public function testAdapterThrowExceptionsWhenProxyingToMissingMethods()
    {
        $this->zfSkip();
        
        $this->adapter->foo();
    }
}