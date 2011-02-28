<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

/**
 * Add attributes to multiple Amazon SimpleDB items or create multiple items
 * using a single request.
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_GetAttributes.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle domain required="true" doc="Domain"
 */
class BatchPutAttributes extends AbstractBatchedCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'BatchPutAttributes';
}