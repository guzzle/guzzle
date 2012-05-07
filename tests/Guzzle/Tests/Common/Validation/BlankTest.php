<?php

namespace Guzzle\Tests\Common\Validation;

/**
 * @covers Guzzle\Common\Validation\Blank
 */
class BlankTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Common\Validation\Blank';
        return array(
            array($c, '', null, true, null),
            array($c, null, null, true, null),
            array($c, false, null, 'Value must be blank', null),
            array($c, 'abc', null, 'Value must be blank', null)
        );
    }
}
