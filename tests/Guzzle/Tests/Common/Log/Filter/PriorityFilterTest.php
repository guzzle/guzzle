<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Log\Filter;

use Guzzle\Common\Log\Filter\PriorityFilter;
use Guzzle\Common\Log\Adapter\LogAdapterInterface;

/**
 * Test class for priority filter
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class PriorityFilterTest extends AbstractFilterTest
{
    /**
     * @outputBuffering enabled
     */
    public function testFilter()
    {
        $this->adapter->getFilterChain()->addFilter(new PriorityFilter(array(
            'priority' => \LOG_CRIT
        )));

        $this->logger->log('Test', \LOG_INFO);

        $this->assertEquals('', ob_get_contents());

        $this->logger->log('Test', \LOG_CRIT);

        $this->assertTrue(strpos(ob_get_contents(), 'Test') !== false);
    }

    /**
     * @expectedException Guzzle\Common\Log\LogException
     * @covers Guzzle\Common\Log\Filter\PriorityFilter::init
     */
    public function testPriorityIsRequired()
    {
        new PriorityFilter();
    }
}