<?php

namespace Guzzle\Tests\Validation;

/**
 * @covers Guzzle\Validation\Type
 * @covers Guzzle\Validation\AbstractConstraint
 * @covers Guzzle\Validation\AbstractType
 */
class TypeTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\Type';
        return array(
            array($c, 'a', array('type' => 'string'), true, null),
            array($c, 'a', array('string'), true, null),
            array($c, '2', array('type' => 'array'), 'Value must be of type array', null)
        );
    }
}
