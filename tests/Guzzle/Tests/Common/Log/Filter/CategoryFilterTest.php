<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Log\Filter;

use Guzzle\Common\Log\Filter\CategoryFilter;
use Guzzle\Common\Log\Adapter\LogAdapterInterface;

/**
 * Test class for category filter
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CategoryFilterTest extends AbstractFilterTest
{
    /**
     * @outputBuffering enabled
     */
    public function testFilter()
    {
        $this->adapter->getFilterChain()->addFilter(new CategoryFilter(array(
            'category' => array(
                'cat1',
                'cat.2.3'
            )
        )));

        $this->logger->log('this is a simple Test...', \LOG_INFO, 'cat1');

        $this->assertTrue(strpos(ob_get_contents(), 'Test') !== false);

        ob_clean();

        $this->logger->log('does not have the magic word', \LOG_CRIT, 'nope');

        $this->assertEquals('', ob_get_contents());

        $this->adapter->getFilterChain()->removeAllFilters();

        $this->logger->log('test', \LOG_INFO, 'cat.2.3');

        $this->assertTrue(strpos(ob_get_contents(), 'test') !== false);
    }

    /**
     * @expectedException Guzzle\Common\Log\LogException
     * @covers Guzzle\Common\Log\Filter\CategoryFilter::init
     */
    public function testCategoryIsRequired()
    {
        new CategoryFilter();
    }
}