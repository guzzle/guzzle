<?php

namespace Guzzle\Tests\Common\Validation;

/**
 * @covers Guzzle\Common\Validation\Bool
 */
class BoolTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Common\Validation\Bool';
        return array(
            array($c, 'on', null, true, null),
            array($c, 'off', null, true, null),
            array($c, 'true', null, true, null),
            array($c, 'false', null, true, null),
            array($c, '1', null, true, null),
            array($c, '0', null, true, null),
            array($c, 1, null, true, null),
            array($c, 0, null, true, null),
            array($c, true, null, true, null),
            array($c, false, null, true, null),
            array($c, 'foo', null, 'Value must be boolean', null)
        );
    }
}
