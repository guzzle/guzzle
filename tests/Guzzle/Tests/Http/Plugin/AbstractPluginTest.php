<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http\Plugin;

use Guzzle\Http\Plugin\Log\LogPlugin;
use Guzzle\Common\Log\Logger;
use Guzzle\Common\Log\Adapter\ClosureLogAdapter;
use Guzzle\Http\Message\RequestFactory;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AbstractPluginTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var LogPlugin
     */
    private $plugin;

    public function setUp()
    {
        $this->plugin = new LogPlugin(new Logger(array(new ClosureLogAdapter(
            function($message, $priority, $category, $host) {
                echo $message . ' ' . $priority . ' ' . $category . ' ' . $host . "\n";
            }
        ))));
    }

    public function tearDown()
    {
        unset($this->plugin);
    }

    /**
     * @covers Guzzle\Http\Plugin\AbstractPlugin::attach
     */
    public function testAttach()
    {
        $request = RequestFactory::getInstance()->newRequest('GET', 'http://www.google.com/');
        $this->assertTrue($this->plugin->attach($request));
        $this->assertTrue($this->plugin->isAttached($request));
        $this->assertFalse($this->plugin->attach($request));
    }

    /**
     * @covers Guzzle\Http\Plugin\AbstractPlugin::detach
     */
    public function testDetach()
    {
        $request = RequestFactory::getInstance()->newRequest('GET', 'http://www.google.com/');
        $this->assertFalse($this->plugin->detach($request));
        $this->assertTrue($this->plugin->attach($request));
        $this->assertTrue($this->plugin->detach($request));
    }
}