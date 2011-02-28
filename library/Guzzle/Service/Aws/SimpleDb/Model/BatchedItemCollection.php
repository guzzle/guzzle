<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Model;

/**
 * A collection of batched Amazon SimpleDB items to manipulate
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class BatchedItemCollection implements \IteratorAggregate, \Countable
{
    /**
     * @var array Array of batched item data
     */
    private $items = array();

    /**
     * Get the item iterator
     *
     * @return ArrayIterator
     */
    public function getIterator()
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * Gets the total number of items in the collection
     *
     * @return int
     */
    public function count()
    {
        return count($this->items);
    }

    /**
     * Clear all of the items from the collection
     *
     * @return BatchedItemCollection
     */
    public function clearItems()
    {
        $this->items = array();

        return $this;
    }

    /**
     * Add an item to the batch collection
     *
     * @param string $itemName Name of the item
     * @param array $data Item data
     * @param bool $replace Set to TRUE to replace any previously set data
     *
     * @return BatchedItemCollection
     */
    public function addItem($itemName, array $data, $replace = false)
    {
        $this->items[$itemName] = $data;
        if ($replace) {
            $this->items[$itemName]['_replace'] = true;
        }

        return $this;
    }

    /**
     * Add multiple items at once to the batch collection
     *
     * @param array $data Data to add.  The array keys of the outer array are
     *      the item names that are being added.  The array values of the outer
     *      array are item attributes.  The attributes array is a key value pair
     *      array of attribute name and attribute value(s).  Pass a key of
     *      '_replace' set to TRUE to replace any existing values.
     *
     * @return BatchedItemCollection
     *
     * <code>
     * $command->addItems(array(
     *     'itemName1' => array(
     *         'attributeName1' => 'attributeValue1',
     *         'attributeName2' => array(
     *             'attributeValue2_1',
     *             'attributeValue2_2'
     *         )
     *     ),
     *     'itemName2' => array(
     *         'attributeName1' => 'attributeValue1'
     *         '_replace' => true
     *     )
     * ));
     * </code>
     */
    public function addItems(array $items)
    {
        foreach ($items as $itemName => $attributes) {
            $this->addItem($itemName, $attributes);
        }

        return $this;
    }

    /**
     * Remove an item from the collection by name
     *
     * @param string $itemName Name of the item to remove by name
     *
     * @return BatchedItemCollection
     */
    public function removeItem($itemName)
    {
        foreach ($this->items as $key => $value) {
            if ($key == $itemName) {
                unset($this->items[$key]);
                break;
            }
        }

        return $this;
    }

    /**
     * Get item data for an item by name
     *
     * @param string $itemName Item name
     *
     * @return array
     */
    public function getItem($itemName)
    {
        return (isset($this->items[$itemName])) ? $this->items[$itemName] : array();
    }

    /**
     * Get all of the items in the collection
     *
     * @param bool $asQueryString (optional) Set to TRUE to retrieve the items
     *      in the format required for a query string request
     */
    public function getItems($asQueryString = false)
    {
        if (!$asQueryString) {
            return $this->items;
        } else {
            
            $itemCount = 0;
            $mapped = array();

            foreach ($this->items as $itemName => $attributes) {

                $itemCount++;
                $attributeCount = 0;
                $mapped["Item.{$itemCount}.ItemName"] = (string)$itemName;

                $replaceAll = (array_key_exists('_replace', $attributes) && $attributes['_replace'] === true);

                foreach ($attributes as $name => $values) {

                    foreach ((array)$values as $value) {
                        if ($value == '_replace' || $name == '_replace') {
                            if (!$replaceAll && ($value === true || $value == '_replace')) {
                                $mapped["Item.{$itemCount}.Attribute.{$attributeCount}.Replace"] = 'true';
                            }
                        } else {
                            $mapped["Item.{$itemCount}.Attribute.{$attributeCount}.Name"] = (string)$name;
                            $mapped["Item.{$itemCount}.Attribute.{$attributeCount}.Value"] = (string)$value;

                            if ($replaceAll) {
                                $mapped["Item.{$itemCount}.Attribute.{$attributeCount}.Replace"] = 'true';
                            }
                            
                            $attributeCount++;
                        }
                    }
                }
            }

            return $mapped;
        }
    }
}