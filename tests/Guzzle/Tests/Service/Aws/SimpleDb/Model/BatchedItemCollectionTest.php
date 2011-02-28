<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\SimpleDb\Model;

use Guzzle\Service\Aws\SimpleDb\Model\BatchedItemCollection;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class BatchedItemCollectionTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Model\BatchedItemCollection
     * @covers Guzzle\Service\Aws\SimpleDb\Model\BatchedItemCollection::getIterator
     * @covers Guzzle\Service\Aws\SimpleDb\Model\BatchedItemCollection::addItems
     * @covers Guzzle\Service\Aws\SimpleDb\Model\BatchedItemCollection::count
     */
    public function testIsLikeAnArray()
    {
        $b = new BatchedItemCollection();

        $data = array(
            'test' => array(
                'a' => '1'
            ),
            'test_2' => array(
                'b' => '2'
            )
        );

        $b->addItems($data);

        $this->assertInstanceOf('ArrayIterator', $b->getIterator());

        $i = 0;
        $keys = array_keys($data);
        foreach ($b as $itemName => $attributes) {
            $this->assertEquals($keys[$i], $itemName);
            $this->assertEquals($data[$itemName], $attributes);
            $i++;
        }

        $this->assertEquals(2, count($b));
    }

    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Model\BatchedItemCollection
     */
    public function testHoldsItems()
    {
        $b = new BatchedItemCollection();

        // Add an item with the _replace attribute set
        $this->assertSame($b, $b->addItem('test', array(
            'a' => 'abc',
            'b' => '123'
        ), true));

        // Test that the data was inserted correctly into the collection
        $this->assertEquals(array(
            'test' => array(
                'a' => 'abc',
                'b' => '123',
                '_replace' => true
            )
        ), $b->getItems());

        // Clear out the items
        $this->assertSame($b, $b->clearItems());
        $this->assertEquals(array(), $b->getItems());

        // Add an item with the _replace flag set on the attribute
        $this->assertSame($b, $b->addItem('test_2', array(
            'a' => array(
                'abc',
                '_replace'
            )
        )));

        // Test that the data was inserted correctly into the collection
        $this->assertEquals(array(
            'test_2' => array(
                'a' => array(
                    'abc',
                    '_replace'
                )
            )
        ), $b->getItems());

        // Retrieve an item by name
        $this->assertEquals(array(
            'a' => array('abc', '_replace')
        ), $b->getItem('test_2'));

        // Make sure empty arrays are returned for missing items
        $this->assertEquals(array(), $b->getItem('doesnotexist'));

        // Remove an item by name
        $this->assertSame($b, $b->removeItem('test_2'));
        $this->assertEquals(array(), $b->getItems());
    }

    /**
     * @covers Guzzle\Service\Aws\SimpleDb\Model\BatchedItemCollection::getItems
     */
    public function testConvertsToQueryParams()
    {
        $b = new BatchedItemCollection();

        $b->addItem('JumboFez', array(
            'color' => array('red', 'brick', 'garnet')
        ), true);

        $b->addItem('PetiteFez', array(
            'color' => array('pink', 'fuscia')
        ));

        $b->addItem('JimmyJohn', array(
            'color' => array('_replace', 'black')
        ));

        $this->assertEquals(array(
            'Item.1.ItemName' => 'JumboFez',
            'Item.1.Attribute.0.Name' => 'color',
            'Item.1.Attribute.0.Value' => 'red',
            'Item.1.Attribute.0.Replace' => 'true',
            'Item.1.Attribute.1.Name' => 'color',
            'Item.1.Attribute.1.Value' => 'brick',
            'Item.1.Attribute.1.Replace' => 'true',
            'Item.1.Attribute.2.Name' => 'color',
            'Item.1.Attribute.2.Value' => 'garnet',
            'Item.1.Attribute.2.Replace' => 'true',
            'Item.2.ItemName' => 'PetiteFez',
            'Item.2.Attribute.0.Name' => 'color',
            'Item.2.Attribute.0.Value' => 'pink',
            'Item.2.Attribute.1.Name' => 'color',
            'Item.2.Attribute.1.Value' => 'fuscia',
            'Item.3.ItemName' => 'JimmyJohn',
            'Item.3.Attribute.0.Replace' => 'true',
            'Item.3.Attribute.0.Name' => 'color',
            'Item.3.Attribute.0.Value' => 'black',
        ), $b->getItems(true));
    }
}