<?php

namespace Guzzle\Tests\Validation;

/**
 * @covers Guzzle\Validation\String
 */
class StringTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\String';
        return array(
            array($c, 'foo', null, true, null),
            array($c, new \stdClass(), null, 'Value must be a string', null),
            array($c, false, null, 'Value must be a string', null)
        );
    }
}
