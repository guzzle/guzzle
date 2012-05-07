<?php

namespace Guzzle\Tests\Common\Validation;

/**
 * @covers Guzzle\Common\Validation\Url
 */
class UrlTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Common\Validation\Url';
        return array(
            array($c, 'foo', null, 'foo is not a valid URL', null),
            array($c, 'http://www.foo.com', null, true, null),
            array($c, 'http://michaeld:foo@www.foo.com/path?query=a&b=c#foo', null, true, null)
        );
    }
}
