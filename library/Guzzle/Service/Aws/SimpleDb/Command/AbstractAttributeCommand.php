<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

use Guzzle\Service\Aws\SimpleDbException;

/**
 * Abstract class for dealing with attributes
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_GetAttributes.html
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractAttributeCommand extends AbstractSimpleDbCommandRequiresDomain
{
    /**
     * {@inheritdoc}
     *
     * @throws SimpleDbException
     */
    protected function build()
    {
        parent::build();
        $this->request->getQuery()->set('ItemName', $this->get('item_name'));

        foreach ($this->getAll(array('/^Attribute[Name]*\.[0-9]+.*+/')) as $key => $value) {
            $this->request->getQuery()->set($key, $value);
        }
    }

    /**
     * Set the item name
     *
     * @param string $itemName The name of the item
     *
     * @return AbstractAttributeCommand
     */
    public function setItemName($itemName)
    {
        return $this->set('item_name', $itemName);
    }

    /**
     * Set the attributes you want to utilize.
     *
     * @param array $attributes An array of numerically indexed attribute names
     *
     * @return AbstractAttributeCommand
     */
    public function setAttributeNames(array $attributes)
    {
        foreach (array_unique(array_values($attributes)) as $index => $attribute) {
            $this->set("AttributeName.{$index}", $attribute);
        }
        
        return $this;
    }
}