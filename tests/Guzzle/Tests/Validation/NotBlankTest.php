<?php

namespace Guzzle\Tests\Validation;

/**
 * @covers Guzzle\Validation\NotBlank
 */
class NotBlankTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\NotBlank';
        return array(
            array($c, 'foo', null, true, null),
            array($c, null, null, 'Value must not be blank', null),
            array($c, '', null, 'Value must not be blank', null)
        );
    }
}
