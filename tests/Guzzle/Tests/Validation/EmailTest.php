<?php

namespace Guzzle\Tests\Validation;

/**
 * @covers Guzzle\Validation\Email
 */
class EmailTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\Email';
        return array(
            array($c, 'a', null, 'Value is not a valid email address', null),
            array($c, 'a@example.com', null, true, null)
        );
    }
}
