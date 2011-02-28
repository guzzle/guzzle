<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

/**
 * Get attributes of an Amazon SimpleDB item
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_GetAttributes.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle domain required="true"
 * @guzzle item_name required="true"
 * @guzzle consistent_read doc="When set to true, ensures that the most recent data is returned."
 */
class GetAttributes extends AbstractAttributeCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetAttributes';

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        parent::build();
        if ($this->get('consistent_read')) {
            $this->request->getQuery()->set('ConsistentRead', 'true');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        parent::process();
        $xml = $this->result;
        $attributes = array();

        // Create a result array and combine all related items into arrays
        foreach ($xml->GetAttributesResult->Attribute as $node) {
            $attribute = (string)$node->Name;
            $value = (string)$node->Value;
            if (!array_key_exists($attribute, $attributes)) {
                $attributes[$attribute] = $value;
            } else if (!is_array($attributes[$attribute])) {
                $attributes[$attribute] = array($attributes[$attribute], $value);
            } else {
                $attributes[$attribute][] = $value;
            }
        }
        
        $this->result = $attributes;
    }

    /**
     * Set whether or not to use the ConsistentRead setting.
     *
     * When set to true, ensures that the most recent data is returned.
     *
     * @param bool $consistentRead Set to TRUE to use ConsistentRead
     *
     * @return GetAttributes
     */
    public function setConsistentRead($consistentRead)
    {
        return $this->set('consistent_read', $consistentRead);
    }
}