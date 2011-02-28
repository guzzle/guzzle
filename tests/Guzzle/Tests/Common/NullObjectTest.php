<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common;

use Guzzle\Common\NullObject;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class NullObjectTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Common\NullObject
     */
    public function testAll()
    {
        $nullObject = new NullObject();
        $this->assertNull($nullObject->isItNull());
        isset($nullObject->isNull);
        $this->assertNull($nullObject->isNull);
        $nullObject->isNull = 10;
        unset($nullObject->isNull);
        $this->assertNull($nullObject->offsetGet('a'));
    }
}