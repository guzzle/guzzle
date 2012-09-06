<?php

namespace Guzzle\Tests\Validation;

/**
 * @covers Guzzle\Validation\Ctype
 * @covers Guzzle\Validation\AbstractConstraint
 * @covers Guzzle\Validation\AbstractType
 */
class CtypeTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\Ctype';
        return array(
            array($c, 'a', array('type' => 'alpha'), true, null),
            array($c, 'a', array('alpha'), true, null),
            array($c, '2', array('type' => 'alpha'), 'Value must be of type alpha', null),
            array($c, ' ', array('type' => 'space'), true, null),
            array($c, 'a', array('type' => 'foo'), null, 'Guzzle\Common\Exception\InvalidArgumentException')
        );
    }
}
