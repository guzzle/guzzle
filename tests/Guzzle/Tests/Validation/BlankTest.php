<?php

namespace Guzzle\Tests\Validation;

/**
 * @covers Guzzle\Validation\Blank
 */
class BlankTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\Blank';
        return array(
            array($c, '', null, true, null),
            array($c, null, null, true, null),
            array($c, false, null, 'Value must be blank', null),
            array($c, 'abc', null, 'Value must be blank', null)
        );
    }
}
