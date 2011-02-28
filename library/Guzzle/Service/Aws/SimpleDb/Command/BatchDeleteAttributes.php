<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

/**
 * Batch delete attributes
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_GetAttributes.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle domain required="true" doc="Domain"
 */
class BatchDeleteAttributes extends AbstractBatchedCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'BatchDeleteAttributes';
}