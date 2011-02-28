<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common;

use Guzzle\Common\Inflector;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class InflectorTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Common\Inflector::snake
     */
    public function testSnake()
    {
        $this->assertEquals('camel_case', Inflector::snake('camelCase'));
        $this->assertEquals('camel_case', Inflector::snake('CamelCase'));
        $this->assertEquals('camel_case_words', Inflector::snake('CamelCaseWords'));
        $this->assertEquals('camel_case_words', Inflector::snake('CamelCase_words'));
        $this->assertEquals('test', Inflector::snake('test'));
        $this->assertEquals('test', Inflector::snake('test'));
        $this->assertEquals('expect100_continue', Inflector::snake('Expect100Continue'));
    }

    /**
     * @covers Guzzle\Common\Inflector::camel
     */
    public function testCamel()
    {
        $this->assertEquals('camelCase', Inflector::camel('camel_case'));
        $this->assertEquals('camelCaseWords', Inflector::camel('camel_case_words'));
        $this->assertEquals('test', Inflector::camel('test'));
        $this->assertEquals('Expect100Continue', ucfirst(Inflector::camel('expect100_continue')));

        // Get from cache
        $this->assertEquals('test', Inflector::camel('test', false));
    }
}