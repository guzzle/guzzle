<?php

namespace Guzzle\Tests\Common\Validation;

/**
 * @covers Guzzle\Common\Validation\Type
 * @covers Guzzle\Common\Validation\AbstractConstraint
 * @covers Guzzle\Common\Validation\AbstractType
 */
class TypeTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Common\Validation\Type';
        return array(
            array($c, 'a', array('type' => 'string'), true, null),
            array($c, 'a', array('string'), true, null),
            array($c, '2', array('type' => 'array'), 'Value must be of type array', null)
        );
    }
}
