<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs\Command;

use Guzzle\Common\Inflector;

/**
 * The GetQueueAttributes action returns one or all attributes of a queue.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle queue_url required="true" doc="URL of the queue to delete"
 * @guzzle attributes required="true" default="All" doc="Array of attributes to retrieve. E.g. All"
 */
class GetQueueAttributes extends AbstractQueueUrlCommand
{
    protected $action = 'GetQueueAttributes';

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        parent::build();

        if ($this->get('attribute')) {
            foreach ((array)$this->get('attribute') as $i => $attribute) {
                $this->request->getQuery()->set('AttributeName.' . ($i + 1), trim($attribute));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        parent::process();

        $this->result = array();
        foreach ($this->xmlResult->GetQueueAttributesResult->Attribute as $attribute) {
            $this->result[Inflector::snake(trim((string)$attribute->Name))] = trim((string)$attribute->Value);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return array Returns an associative array of data containing the
     *      retrieved attribute names represented in snake case as the array
     *      keys, and the attribute values as the corresponding array values
     */
    public function getResult()
    {
        return parent::getResult();
    }

    /**
     * Add an attribute to the request
     *
     * @param string $attribute Attribute to add
     *
     * @return GetQueueAttributes
     */
    public function addAttribute($attribute)
    {
        return $this->add('attribute', $attribute);
    }
}