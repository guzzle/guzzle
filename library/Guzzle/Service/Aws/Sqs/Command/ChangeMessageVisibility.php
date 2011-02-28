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
 * @guzzle receipt_handle required="true" doc="The receipt handle associated with the message"
 * @guzzle visibility_timeout required="true" doc="The new value for the message's visibility timeout (in seconds)."
 */
class ChangeMessageVisibility extends AbstractQueueUrlCommand
{
    protected $action = 'ChangeMessageVisibility';

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        parent::build();

        $this->request->getQuery()->set('ReceiptHandle', $this->get('receipt_handle'));
        $this->request->getQuery()->set('VisibilityTimeout', $this->get('visibility_timeout'));
    }

    /**
     * Set the receipt handle associated with the message
     *
     * @param string $receiptHandle Receipt handle
     *
     * @return ChangeMessageVisibility
     */
    public function setReceiptHandle($receiptHandle)
    {
        return $this->set('receipt_handle', $receiptHandle);
    }

    /**
     * Sets the visibility timeout to use for the message in seconds
     *
     * @param int $seconds Number of seconds until a visibility timeout
     *
     * @return ChangeMessageVisibility
     */
    public function setVisibilityTimeout($seconds)
    {
        return $this->set('visibility_timeout', (int)$seconds);
    }
}