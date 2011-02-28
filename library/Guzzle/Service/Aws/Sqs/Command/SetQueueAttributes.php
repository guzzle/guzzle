<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs\Command;

/**
 * The SetQueueAttributes action sets an attribute of a queue. When you change
 * a queue's attributes, the change can take up to 60 seconds to propagate
 * throughout the SQS system.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle queue_url required="true" doc="URL of the queue"
 * @guzzle attributes required="true" doc="Associative array of attributes to set"
 */
class SetQueueAttributes extends AbstractQueueUrlCommand
{
    const VISIBILITY_TIMEOUT = 'VisibilityTimeout';
    const POLICY = 'Policy';
    const MAXIMUM_MESSAGE_SIZE = 'MaximumMessageSize';
    const MESSAGE_RETENTION_PERIOD = 'MessageRetentionPeriod';

    protected $action = 'SetQueueAttributes';

    /**
     * {@inheritdoc{
     */
    protected function init()
    {
        $this->set('attributes', array());
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        parent::build();

        $qs = $this->request->getQuery();
        foreach ($this->get('attributes') as $i => $attr) {
            $qs->set('Attribute.' . ($i + 1) . '.Name', $attr['name']);
            $qs->set('Attribute.' . ($i + 1) . '.Value', $attr['value']);
        }
    }

    /**
     * Add an attribute to the request
     *
     * @param string $name Attribute to set
     * @param string $value Value to set
     *
     * @return SetQueueAttributes
     */
    public function addAttribute($name, $value)
    {
        return $this->add('attributes', array(
            'name' => $name,
            'value' => $value
        ));
    }
}