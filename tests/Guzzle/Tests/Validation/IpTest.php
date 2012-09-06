<?php

namespace Guzzle\Tests\Validation;

/**
 * @covers Guzzle\Validation\Ip
 */
class IpTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\Ip';
        return array(
            array($c, 'a', null, 'Value is not a valid IP address', null),
            array($c, '192.168.16.121', null, true, null)
        );
    }
}
