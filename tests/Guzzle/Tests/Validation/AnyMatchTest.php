<?php

namespace Guzzle\Tests\Validation;

use Guzzle\Validation\AnyMatch;
use Guzzle\Service\Inspector;

/**
 * @covers Guzzle\Validation\AnyMatch
 */
class AnyMatchTest extends Validation
{
    public function provider()
    {
        $c = 'Guzzle\Validation\AnyMatch';

        if (!class_exists('Guzzle\Service\Inspector')) {
            $this->markTestSkipped('Inspector not present');
        }

        $i = Inspector::getInstance();

        return array(
            array($c, 'a', array(
                'constraints' => array('type:string'),
                'inspector' => $i
            ), true, null),
            array($c, 'a', array(
                array('type:string'),
                'inspector' => $i
            ), true, null),
            array($c, 'foo', array(
                'constraints' => array('type:string', 'type:numeric')
            ), true, null),
            array($c, new \stdClass(), array(
                'constraints' => array('type:string', 'type:numeric'),
                'inspector' => $i
            ), 'Value does not satisfy complex constraints', null),
            array($c, 'foo', array(
                'constraints' => array('type:numeric', 'type:boolean', 'ip', 'email'),
                'inspector' => $i
            ), 'Value does not satisfy complex constraints', null),
            array($c, 'http://www.example.com', array(
                'constraints' => array(array('type' => 'ip'), 'url'),
                'inspector' => $i
            ), true, null),
            array($c, '192.168.16.148', array(
                'constraints' => array('ip', 'url'),
                'inspector' => $i
            ), true, null),
            array($c, 'foo', array(
                'constraints' => array(
                    'email',
                    array(
                        'type' => 'choice',
                        'type_args' => array(
                            'options' => array('foo', 'bar', 'ip', 'array')
                        )
                    ),
                    'inspector' => $i
                )
            ), true, null),
            array($c, 'bar', array(
                'constraints' => array('email', array('type' => 'choice', 'type_args' => array('options' => array('foo' ,'bar', 'ip')))),
                'inspector' => $i
            ), true, null),
            array($c, '192.168.16.48', array(
                'constraints' => array('email', array('type' => 'choice', 'type_args' => array('options' => array('foo','bar', 'ip')))),
                'inspector' => $i
            ), 'Value does not satisfy complex constraints', null),
            array($c, array(), array(
                'constraints' => array('email', array('type' => 'choice', 'type_args' => array('options' => array('foo','bar', 'ip')))),
                'inspector' => $i
            ), 'Value does not satisfy complex constraints', null),
            array($c, 'michael@awesome.com', array(
                'constraints' => array('email', array('type' => 'choice', 'type_args' => array('options' => array('foo','bar', 'ip')))),
                'inspector' => $i
            ), true, null),
            array($c, new \stdClass(), array(
                'constraints' => array('type:object'),
                'inspector' => $i
            ), true, null)
        );
    }
}
