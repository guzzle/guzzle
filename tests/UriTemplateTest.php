<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\UriTemplate;
use PHPUnit\Framework\TestCase;

/**
 * @covers GuzzleHttp\UriTemplate
 */
class UriTemplateTest extends TestCase
{
    /**
     * @return array
     */
    public function templateProvider()
    {
        $params = array(
            'var'   => 'value',
            'hello' => 'Hello World!',
            'empty' => '',
            'path'  => '/foo/bar',
            'x'     => '1024',
            'y'     => '768',
            'null'  => null,
            'list'  => array('red', 'green', 'blue'),
            'keys'  => array(
                "semi"  => ';',
                "dot"   => '.',
                "comma" => ','
            ),
            'empty_keys' => array(),
        );

        return array_map(function ($t) use ($params) {
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
            array('{.null}',            ''),
            array('{.null,var}',        '.value'),
            array('X{.empty_keys*}',     'X'),
            array('X{.empty_keys}',      'X'),
            // Test that missing expansions are skipped
            array('test{&missing*}',     'test'),
            // Test that multiple expansions can be set
            array('http://{var}/{var:2}{?keys*}', 'http://value/va?semi=%3B&dot=.&comma=%2C'),
            // Test more complex query string stuff
            array('http://www.test.com{+path}{?var,keys*}', 'http://www.test.com/foo/bar?var=value&semi=%3B&dot=.&comma=%2C')
        ));
    }

    /**
     * @dataProvider templateProvider
     */
    public function testExpandsUriTemplates($template, $expansion, $params)
    {
        $uri = new UriTemplate();
        $this->assertSame($expansion, $uri->expand($template, $params));
    }

    public function expressionProvider()
    {
        return array(
            array(
                '{+var*}', array(
                'operator' => '+',
                'values'   => array(
                    array('modifier' => '*', 'value' => 'var')
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
        $template = new UriTemplate();

        // Access the config object
        $class = new \ReflectionClass($template);
        $method = $class->getMethod('parseExpression');
        $method->setAccessible(true);

        $exp = substr($exp, 1, -1);
        $this->assertSame($data, $method->invokeArgs($template, array($exp)));
    }

    /**
     * @ticket https://github.com/guzzle/guzzle/issues/90
     */
    public function testAllowsNestedArrayExpansion()
    {
        $template = new UriTemplate();

        $result = $template->expand('http://example.com{+path}{/segments}{?query,data*,foo*}', array(
            'path'     => '/foo/bar',
            'segments' => array('one', 'two'),
            'query'    => 'test',
            'data'     => array(
                'more' => array('fun', 'ice cream')
            ),
            'foo' => array(
                'baz' => array(
                    'bar'  => 'fizz',
                    'test' => 'buzz'
                ),
                'bam' => 'boo'
            )
        ));

        $this->assertSame('http://example.com/foo/bar/one,two?query=test&more%5B0%5D=fun&more%5B1%5D=ice%20cream&baz%5Bbar%5D=fizz&baz%5Btest%5D=buzz&bam=boo', $result);
    }
}
