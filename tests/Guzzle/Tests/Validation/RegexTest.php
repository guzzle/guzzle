<?php

namespace Guzzle\Tests\Validation;

/**
 * @covers Guzzle\Validation\Regex
 * @covers Guzzle\Validation\AbstractConstraint
 */
class RegexTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\Regex';
        return array(
            array($c, 'foo', array('pattern' => '/[a-z]+/'), true, null),
            array($c, 'foo', array('/[a-z]+/'), true, null),
            array($c, 'foo', array('pattern' => '/[0-9]+/'), 'foo does not match the regular expression', null),
            array($c, 'baz', null, null, 'Guzzle\Common\Exception\InvalidArgumentException')
        );
    }
}
