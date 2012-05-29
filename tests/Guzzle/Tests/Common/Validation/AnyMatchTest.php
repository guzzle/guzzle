<?php

namespace Guzzle\Tests\Common\Validation;

use Guzzle\Common\Validation\AnyMatch;
use Guzzle\Service\Inspector;

/**
 * @covers Guzzle\Common\Validation\AnyMatch
 */
class AnyMatchTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Common\Validation\AnyMatch';

        if (!class_exists('Guzzle\Service\Inspector')) {
            $this->markTestSkipped('Inspector not present');
        }

        $i = Inspector::getInstance();

        return array(
            array($c, 'a', array('constraints' => 'type:string', 'inspector' => $i), true, null),
            array($c, 'a', array('type:string', 'inspector' => $i), true, null),
            array($c, 'foo', array('constraints' => 'type:string;type:numeric'), true, null),
            array($c, new \stdClass(), array('constraints' => 'type:string;type:numeric', 'inspector' => $i), 'Value type must match one of type:string OR type:numeric', null),
            array($c, 'foo', array('constraints' => 'type:numeric;type:boolean;ip;email', 'inspector' => $i), 'Value type must match one of type:numeric OR type:boolean OR ip OR email', null),
            array($c, 'http://www.example.com', array('constraints' => 'ip;url', 'inspector' => $i), true, null),
            array($c, '192.168.16.148', array('constraints' => 'ip;url', 'inspector' => $i), true, null),
            array($c, 'foo', array('constraints' => 'email;choice:foo,bar;ip;array', 'inspector' => $i), true, null),
            array($c, 'bar', array('constraints' => 'email;choice:foo,bar;ip;array', 'inspector' => $i), true, null),
            array($c, '192.168.16.48', array('constraints' => 'email;choice:foo,bar;ip;array', 'inspector' => $i), true, null),
            array($c, array(), array('constraints' => 'email;choice:foo,bar;ip;array', 'inspector' => $i), true, null),
            array($c, 'michael@awesome.com', array('constraints' => 'email;choice:foo,bar;ip;array', 'inspector' => $i), true, null),
            array($c, new \stdClass(), array('constraints' => 'type:object', 'inspector' => $i), true, null)
        );
    }
}
