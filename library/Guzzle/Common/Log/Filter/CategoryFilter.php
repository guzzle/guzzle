<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Log\Filter;

use Guzzle\Common\Filter\AbstractFilter;
use Guzzle\Common\Log\LogException;

/**
 * Filters log messages based on the log category
 *
 * A 'category' value must be passed to this filter's constuctor.  The category
 * value can be a string representing a category or an array of category
 * strings.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CategoryFilter extends AbstractFilter
{
    /**
     * {@inheritdoc}
     *
     * @throws LogFilterException
     */
    protected function init()
    {
        if (!$this->get('category')) {
            throw new LogException(
                'A category value must be specified on a category log filter'
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function filterCommand($command)
    {
        foreach ((array) $this->get('category') as $category) {
            if (0 == strcasecmp($command['category'], $category)) {
                return true;
            }
        }

        return false;
    }
}