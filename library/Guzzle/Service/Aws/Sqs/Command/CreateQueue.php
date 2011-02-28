<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs\Command;

use \Guzzle\Http\Message\RequestInterface;

/**
 * Create a new queue
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle queue_name required="true" doc="Name of the queue to create"
 * @guzzle default_visibility_timeout doc="The visibility timeout (in seconds) to use for this queue."
 */
class CreateQueue extends AbstractCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest(RequestInterface::GET);
        $this->request->getQuery()->set('Action', 'CreateQueue')
            ->set('QueueName', $this->get('queue_name'));

        if ($this->get('default_visibility_timeout')) {
            $this->request->getQuery()->set('DefaultVisibilityTimeout', $this->get('default_visibility_timeout'));
        }
    }

    /**
     * Set the name of the queue to create
     *
     * @param string $queueName Name of the queue
     *
     * @return CreateQueue
     */
    public function setQueueName($queueName)
    {
        return $this->set('queue_name', $queueName);
    }

    /**
     * Sets the default visibility timeout to use for the queue in seconds
     *
     * @param int $seconds Number of seconds until a visibility timeout
     *
     * @return CreateQueue
     */
    public function setDefaultVisibilityTimeout($seconds)
    {
        return $this->set('default_visibility_timeout', (int)$seconds);
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        parent::process();
        $this->result = trim((string)$this->xmlResult->CreateQueueResult->QueueUrl);
    }

    /**
     * Returns the created queue URL
     *
     * @return string
     */
    public function getResult()
    {
        return parent::getResult();
    }
}