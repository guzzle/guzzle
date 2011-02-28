<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common\Log\Filter;

use Guzzle\Common\Log\Filter\ContainsFilter;
use Guzzle\Common\Log\Adapter\LogAdapterInterface;

/**
 * Test class for contains filter
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ContainsFilterTest extends AbstractFilterTest
{
    /**
     * @outputBuffering enabled
     */
    public function testFilter()
    {
        $this->adapter->getFilterChain()->addFilter(new ContainsFilter(array(
            'match' => 'test'
        )));

        $this->logger->log('this is a simple Test...', \LOG_INFO);

        $this->assertTrue(strpos(ob_get_contents(), 'Test') !== false);

        ob_clean();

        $this->logger->log('does not have the magic word', \LOG_CRIT);

        $this->assertEquals('', ob_get_contents());

        $this->adapter->getFilterChain()->removeAllFilters();

        $this->adapter->getFilterChain()->addFilter(new ContainsFilter(array(
            'match' => array(
                'test',
                '/[a-z]\.[0-9]/'
            )
        )));

        $this->logger->log('does not have the magic word', \LOG_CRIT);

        $this->assertEquals('', ob_get_contents());

        $this->logger->log('this is a simple Test...', \LOG_INFO);

        $this->assertTrue(strpos(ob_get_contents(), 'Test') !== false);

        ob_clean();

        $this->logger->log('regexp [b.3] check', \LOG_INFO);

        $this->assertTrue(strpos(ob_get_contents(), 'regexp [b.3] check') !== false);
    }

    /**
     * @expectedException Guzzle\Common\Log\LogException
     * @covers Guzzle\Common\Log\Filter\ContainsFilter::init
     */
    public function testMatchIsRequired()
    {
        new ContainsFilter();
    }
}