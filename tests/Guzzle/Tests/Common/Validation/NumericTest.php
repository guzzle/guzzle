<?php

namespace Guzzle\Tests\Common\Validation;

/**
 * @covers Guzzle\Common\Validation\Numeric
 */
class NumericTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Common\Validation\Numeric';
        return array(
            array($c, '123', null, true, null),
            array($c, 123, null, true, null),
            array($c, '-10', null, true, null),
            array($c, 'abc', null, 'Value must be numeric', null)
        );
    }
}
