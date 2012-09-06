<?php

namespace Guzzle\Tests\Validation;

/**
 * @covers Guzzle\Validation\Choice
 */
class ChoiceTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\Choice';
        return array(
            array($c, 'foo', array('options' => array('foo', 'bar')), true, null),
            array($c, 'baz', array('options' => array('foo', 'bar')), 'Value must be one of: foo, bar', null)
        );
    }
}
