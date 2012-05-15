<?php

namespace Guzzle\Tests\Http\Parsers\UriTemplate;

use Guzzle\Http\Parser\UriTemplate\UriTemplate;

/**
 * @covers Guzzle\Http\Parser\UriTemplate\UriTemplate
 */
class UriTemplateTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @return array
     */
    public function templateProvider()
    {
        $t = array();

        // Level 1 template tests
        $params = array(
            'var'   => 'value',
            'hello' => 'Hello World!',
            'empty' => '',
            'path'  => '/foo/bar',
            'x'     => '1024',
            'y'     => '768',
            'list'  => array('red', 'green', 'blue'),
            'keys'  => array(
                "semi"  => ';',
                "dot"   => '.',
                "comma" => ','
            )
        );

        return array_map(function($t) use ($params) {
            $t[] = $params;
            return $t;
        }, array(
            array('foo',                 'foo'),
            array('{var}',               'value'),
            array('{hello}',             'Hello%20World%21'),
            array('{+var}',              'value'),
            array('{+hello}',            'Hello%20World!'),
            array('{+path}/here',        '/foo/bar/here'),
            array('here?ref={+path}',    'here?ref=/foo/bar'),
            array('X{#var}',             'X#value'),
            array('X{#hello}',           'X#Hello%20World!'),
            array('map?{x,y}',           'map?1024,768'),
            array('{x,hello,y}',         '1024,Hello%20World%21,768'),
            array('{+x,hello,y}',        '1024,Hello%20World!,768'),
            array('{+path,x}/here',      '/foo/bar,1024/here'),
            array('{#x,hello,y}',        '#1024,Hello%20World!,768'),
            array('{#path,x}/here',      '#/foo/bar,1024/here'),
            array('X{.var}',             'X.value'),
            array('X{.x,y}',             'X.1024.768'),
            array('{/var}',              '/value'),
            array('{/var,x}/here',       '/value/1024/here'),
            array('{;x,y}',              ';x=1024;y=768'),
            array('{;x,y,empty}',        ';x=1024;y=768;empty'),
            array('{?x,y}',              '?x=1024&y=768'),
            array('{?x,y,empty}',        '?x=1024&y=768&empty='),
            array('?fixed=yes{&x}',      '?fixed=yes&x=1024'),
            array('{&x,y,empty}',        '&x=1024&y=768&empty='),
            array('{var:3}',             'val'),
            array('{var:30}',            'value'),
            array('{list}',              'red,green,blue'),
            array('{list*}',             'red,green,blue'),
            array('{keys}',              'semi,%3B,dot,.,comma,%2C'),
            array('{keys*}',             'semi=%3B,dot=.,comma=%2C'),
            array('{+path:6}/here',      '/foo/b/here'),
            array('{+list}',             'red,green,blue'),
            array('{+list*}',            'red,green,blue'),
            array('{+keys}',             'semi,;,dot,.,comma,,'),
            array('{+keys*}',            'semi=;,dot=.,comma=,'),
            array('{#path:6}/here',      '#/foo/b/here'),
            array('{#list}',             '#red,green,blue'),
            array('{#list*}',            '#red,green,blue'),
            array('{#keys}',             '#semi,;,dot,.,comma,,'),
            array('{#keys*}',            '#semi=;,dot=.,comma=,'),
            array('X{.var:3}',           'X.val'),
            array('X{.list}',            'X.red,green,blue'),
            array('X{.list*}',           'X.red.green.blue'),
            array('X{.keys}',            'X.semi,%3B,dot,.,comma,%2C'),
            array('X{.keys*}',           'X.semi=%3B.dot=..comma=%2C'),
            array('{/var:1,var}',        '/v/value'),
            array('{/list}',             '/red,green,blue'),
            array('{/list*}',            '/red/green/blue'),
            array('{/list*,path:4}',     '/red/green/blue/%2Ffoo'),
            array('{/keys}',             '/semi,%3B,dot,.,comma,%2C'),
            array('{/keys*}',            '/semi=%3B/dot=./comma=%2C'),
            array('{;hello:5}',          ';hello=Hello'),
            array('{;list}',             ';list=red,green,blue'),
            array('{;list*}',            ';list=red;list=green;list=blue'),
            array('{;keys}',             ';keys=semi,%3B,dot,.,comma,%2C'),
            array('{;keys*}',            ';semi=%3B;dot=.;comma=%2C'),
            array('{?var:3}',            '?var=val'),
            array('{?list}',             '?list=red,green,blue'),
            array('{?list*}',            '?list=red&list=green&list=blue'),
            array('{?keys}',             '?keys=semi,%3B,dot,.,comma,%2C'),
            array('{?keys*}',            '?semi=%3B&dot=.&comma=%2C'),
            array('{&var:3}',            '&var=val'),
            array('{&list}',             '&list=red,green,blue'),
            array('{&list*}',            '&list=red&list=green&list=blue'),
            array('{&keys}',             '&keys=semi,%3B,dot,.,comma,%2C'),
            array('{&keys*}',            '&semi=%3B&dot=.&comma=%2C'),
            // Test that missing expansions are skipped
            array('test{&missing*}',     'test'),
            // Test that multiple expansions can be set
            array('http://{var}/{var:2}{?keys*}', 'http://value/va?semi=%3B&dot=.&comma=%2C'),
            // Test that it is backwards compatible with {{ }} syntax
            array('{{var}}|{{var:3}}',             'value|val'),
            // Test more complex query string stuff
            array('http://www.test.com{+path}{?var,keys*}', 'http://www.test.com/foo/bar?var=value&semi=%3B&dot=.&comma=%2C')
        ));
    }

    /**
     * @dataProvider templateProvider
     */
    public function testExpandsUriTemplates($template, $expansion, $params)
    {
        $uri = new UriTemplate($template);
        $result = $uri->expand($template, $params);
        $this->assertEquals($expansion, $result);
    }

    public function expressionProvider()
    {
        return array(
            array(
                '{+var*}', array(
                    'operator' => '+',
                    'values'   => array(
                        array('value' => 'var', 'modifier' => '*')
                    )
                ),
            ),
            array(
                '{?keys,var,val}', array(
                    'operator' => '?',
                    'values'   => array(
                        array('value' => 'keys', 'modifier' => ''),
                        array('value' => 'var', 'modifier' => ''),
                        array('value' => 'val', 'modifier' => '')
                    )
                ),
            ),
            array(
                '{+x,hello,y}', array(
                    'operator' => '+',
                    'values'   => array(
                        array('value' => 'x', 'modifier' => ''),
                        array('value' => 'hello', 'modifier' => ''),
                        array('value' => 'y', 'modifier' => '')
                    )
                )
            )
        );
    }

    /**
     * @dataProvider expressionProvider
     */
    public function testParsesExpressions($exp, $data)
    {
        $template = new UriTemplate($exp);

        // Access the config object
        $class = new \ReflectionClass($template);
        $method = $class->getMethod('parseExpression');
        $method->setAccessible(true);

        $exp = substr($exp, 1, -1);
        $this->assertEquals($data, $method->invokeArgs($template, array($exp)));
    }
}
