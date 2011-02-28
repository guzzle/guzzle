<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

use Guzzle\Service\Aws\SimpleDb\Model\BatchedItemCollection;

/**
 * Abstract batched SimpleDB command
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AbstractBatchedCommand extends AbstractSimpleDbCommandRequiresDomain
{
    /**
     * @var BatchedItemCollection Batched items
     */
    protected $batched;

    /**
     * {@inheritdoc}
     */
    protected function init()
    {
        $this->batched = new BatchedItemCollection();
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        parent::build();
        $this->request->getQuery()->merge($this->batched->getItems(true));
    }

    /**
     * Set the batched item collection object
     *
     * @param BatchedItemCollection $collection Collection to set
     *
     * @return AbstractBatchedCommand
     */
    public function setBatchedItemCollection(BatchedItemCollection $collection)
    {
        $this->batched = $collection;

        return $this;
    }

    /**
     * Get the batched item collection object
     *
     * @return BatchedItemCollection
     */
    public function getBatchedItemCollection()
    {
        return $this->batched;
    }

    /**
     * Clear all of the items in the command
     *
     * @return AbstractBatchedCommand
     */
    public function clearItems()
    {
        $this->batched->clearItems();

        return $this;
    }

    /**
     * Get all of the items queued in the command
     *
     * @return array
     */
    public function getItems()
    {
        return $this->batched->getItems();
    }

    /**
     * Add items and attributes to the command
     *
     * @param array $attributes Item data consisting of an outer array in which
     *      the key is the name of the item, and an inner array in which the
     *      key is the attribute name and the value is either a single
     *      attribute value or an array of attribute values.     *
     * @param bool $replace (optional) Set to TRUE to replace existing items
     *
     * @return AbstractBatchedCommand
     *
     * @see BatchedItemCollection::addItems
     */
    public function addItems(array $items)
    {
        $this->batched->addItems($items);

        return $this;
    }

    /**
     * Add an item to the batched item collection
     *
     * @param string $itemName Name of the item to add
     * @param array $data Data to add
     * @param bool $replace (optional) Set to TRUE to replace attribute data
     *
     * @return AbstractBatchedCommand
     *
     * @see BatchedItemCollection::addItem
     */
    public function addItem($itemName, array $data, $replace = false)
    {
        $this->batched->addItem($itemName, $data, $replace);

        return $this;
    }
}