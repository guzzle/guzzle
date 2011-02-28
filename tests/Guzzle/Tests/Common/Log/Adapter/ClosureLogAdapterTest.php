<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Log\Adapter;

use Guzzle\Common\Log\Adapter\LogAdapterInterface;
use Guzzle\Common\Log\Adapter\ClosureLogAdapter;

/**
 * Test class for ClosureLogAdapter
 * 
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ClosureLogAdapterTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var string Variable that the closure will modifiy
     */
    public $modified;

    /**
     * @covers \Guzzle\Common\Log\Adapter\ClosureLogAdapter
     */
    public function testClosure()
    {
        $that = $this;

        $this->adapter = new ClosureLogAdapter(function($message, $priority, $category, $host) use ($that) {
            $that->modified = array($message, $priority, $category, $host);
        });

        $this->adapter->log('test', \LOG_NOTICE, 'closure', 'localhost');
        $this->assertEquals(array('test', \LOG_NOTICE, 'closure', 'localhost'), $this->modified);
    }
}