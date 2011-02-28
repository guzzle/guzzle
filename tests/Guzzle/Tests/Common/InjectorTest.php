<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common;

use Guzzle\Common\Injector;
use Guzzle\Common\Collection;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class InjectorTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function dataProvider()
    {
        return array(
            array('this_is_a_test', '{{ a }}_is_a_{{ b }}', array(
                'a' => 'this',
                'b' => 'test'
            )),
            array('this_is_a_test', '{{abc}}_is_a_{{ 0 }}', array(
                'abc' => 'this',
                0 => 'test'
            )),
            array('this_is_a_test', '{{ abc }}_is_{{ not_found }}a_{{ 0 }}', array(
                'abc' => 'this',
                0 => 'test'
            )),
            array('this_is_a_test', 'this_is_a_test', array(
                'abc' => 'this'
            )),
            array('_is_a_', '{{ abc }}_is_{{ not_found }}a_{{ 0 }}', array()),
        );
    }

    /**
     * @covers Guzzle\Common\Injector::inject
     * @dataProvider dataProvider
     */
    public function testInjectsConfigData($output, $input, $config)
    {
        $this->assertEquals($output, Injector::inject($input, new Collection($config)));
    }
}