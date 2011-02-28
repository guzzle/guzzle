<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common;

use Guzzle\Common\NullIterator;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class NullIteratorTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Common\NullIterator
     */
    public function testAll()
    {
        $nullObject = new NullIterator();
        $this->assertNull($nullObject->count());
        $this->assertNull($nullObject->key());
        $this->assertNull($nullObject->next());
        $this->assertNull($nullObject->rewind());
        $this->assertNull($nullObject->valid());
        $this->assertNull($nullObject->current());
    }
}