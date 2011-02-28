<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs\Command;

/**
 * Abstract Amazon SQS command which uses a queue URL
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractQueueUrlCommand extends AbstractCommand
{
    /**
     * @var string Action to take on the service
     */
    protected $action;

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest('GET');
        $this->request->setUrl($this->get('queue_url') . '?Action=' . $this->action);
    }

    /**
     * Set the queue URL
     *
     * @param string $url The full SQS queue URL
     *
     * @return AbstractQueueUrlCommand
     */
    public function setQueueUrl($url)
    {
        return $this->set('queue_url', $url);
    }
}