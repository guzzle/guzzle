<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Log\Adapter;

use Guzzle\Common\Log\Adapter\LogAdapterInterface;
use Guzzle\Common\Log\Adapter\SimpleDbLogAdapter;
use Guzzle\Service\Aws\SimpleDb\SimpleDbClient;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Server\Server;
use Guzzle\Common\Subject\Observer;
use Guzzle\Common\Subject\SubjectMediator;

/**
 * Test class for SimpleDbLogAdapter
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SimpleDbLogAdapterTest extends \Guzzle\Tests\GuzzleTestCase implements Observer
{
    /**
     * @var SimpleDbLogAdapter
     */
    protected $adapter;

    /**
     * @var SimpleDbClient
     */
    protected $client;

    /**
     * @var int The number of requests issued by the Request object
     */
    protected $requestCount = 0;

    protected function setUp()
    {
        $this->requestCount = 0;
        $this->client = $this->getServiceBuilder()->getClient('test.simple_db');
        $this->setMockResponse($this->client, 'BatchPutAttributesResponse');
        $that = $this;

        $this->client->getCreateRequestChain()->addFilter(new \Guzzle\Common\Filter\ClosureFilter(function($command) use($that) {
            $command->getSubjectMediator()->attach($that);
        }));
        
        // Wrap the logger and create a new SimpleDbLogAdapter
        $this->adapter = new SimpleDbLogAdapter($this->client, array(
            'domain' => 'test'
        ));
    }

    /**
     * @covers Guzzle\Common\Log\Adapter\SimpleDbLogAdapter::init
     * @expectedException Guzzle\Common\Log\Adapter\LogAdapterException
     */
    public function testDomainMustBePassedInConstructor()
    {
        // Throws an exception
        $this->adapter = new SimpleDbLogAdapter($this->client);
    }

    /**
     * @covers Guzzle\Common\Log\Adapter\SimpleDbLogAdapter::logMessage
     * @covers Guzzle\Common\Log\Adapter\SimpleDbLogAdapter::flush
     * @covers Guzzle\Common\Log\Adapter\AbstractQueuedLogAdapter::setMaxQueueSize
     */
    public function testLog()
    {
        // Set the max queue size to 2 so that 2 or more log messages will
        // trigger a flush
        $this->adapter->setMaxQueueSize(2);

        $this->adapter->log('Test', \LOG_NOTICE, 'guzzle', 'localhost');
        $this->adapter->log('Test 2', \LOG_NOTICE);

        // Two log messages were written, so make sure that a request was sent
        $this->assertEquals(1, $this->requestCount);
    }

    /**
     * @covers Guzzle\Common\Log\Adapter\AbstractQueuedLogAdapter::__destruct
     */
    public function testDestructorMustFlushLogs()
    {
        $this->adapter->setMaxQueueSize(999);
        $this->adapter->log('test');
        unset($this->adapter);
        
        // The __destruct() method was called and should flush the above log
        $this->assertEquals(1, $this->requestCount);
    }

    public function update(SubjectMediator $subject)
    {
        if ($subject->getState() == 'request.success') {
            $this->requestCount++;
        }
    }
}