<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs\Command;

/**
 * Delete a queue
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle queue_url required="true" doc="URL of the queue to delete"
 */
class DeleteQueue extends AbstractQueueUrlCommand
{
    protected $action = 'DeleteQueue';
}