<?php
namespace GuzzleHttp\Test;

use GuzzleHttp;

class FunctionsTest extends \PHPUnit_Framework_TestCase
{
    public function testRetrievesNestedKeysUsingPath()
    {
        $data = array(
            'foo' => 'bar',
            'baz' => array(
                'mesa' => array(
                    'jar' => 'jar'
                )
            )
        );
        $this->assertEquals('bar', GuzzleHttp\get_path($data, 'foo'));
        $this->assertEquals('jar', GuzzleHttp\get_path($data, 'baz/mesa/jar'));
        $this->assertNull(GuzzleHttp\get_path($data, 'wewewf'));
        $this->assertNull(GuzzleHttp\get_path($data, 'baz/mesa/jar/jar'));
    }

    public function testFalseyKeysStillDescend()
    {
        $data = ['0' => ['a' => 'jar'], 1 => 'other'];
        $this->assertEquals('jar', GuzzleHttp\get_path($data, '0/a'));
        $this->assertEquals('other', GuzzleHttp\get_path($data, '1'));
    }

    public function getPathProvider()
    {
        $data = array(
            'foo' => 'bar',
            'baz' => array(
                'mesa' => array(
                    'jar' => 'jar',
                    'array' => array('a', 'b', 'c')
                ),
                'bar' => array(
                    'baz' => 'bam',
                    'array' => array('d', 'e', 'f')
                )
            ),
            'bam' => array(
                array('foo' => 1),
                array('foo' => 2),
                array('array' => array('h', 'i'))
            )
        );

        return [
            // Simple path selectors
            [$data, 'foo', 'bar'],
            [$data, 'baz', $data['baz']],
            [$data, 'bam', $data['bam']],
            [$data, 'baz/mesa', $data['baz']['mesa']],
            [$data, 'baz/mesa/jar', 'jar'],
            // Does not barf on missing keys
            [$data, 'fefwfw', null],
            [$data, 'baz/mesa/array', $data['baz']['mesa']['array']]
        ];
    }

    /**
     * @dataProvider getPathProvider
     */
    public function testGetPath(array $c, $path, $expected)
    {
        $this->assertEquals($expected, GuzzleHttp\get_path($c, $path));
    }

    public function testCanSetNestedPathValueThatDoesNotExist()
    {
        $c = [];
        GuzzleHttp\set_path($c, 'foo/bar/baz/123', 'hi');
        $this->assertEquals('hi', $c['foo']['bar']['baz']['123']);
    }

    public function testCanSetNestedPathValueThatExists()
    {
        $c = ['foo' => ['bar' => 'test']];
        GuzzleHttp\set_path($c, 'foo/bar', 'hi');
        $this->assertEquals('hi', $c['foo']['bar']);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testVerifiesNestedPathIsValidAtExactLevel()
    {
        $c = ['foo' => 'bar'];
        GuzzleHttp\set_path($c, 'foo/bar', 'hi');
        $this->assertEquals('hi', $c['foo']['bar']);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testVerifiesThatNestedPathIsValidAtAnyLevel()
    {
        $c = ['foo' => 'bar'];
        GuzzleHttp\set_path($c, 'foo/bar/baz', 'test');
    }

    public function testCanAppendToNestedPathValues()
    {
        $c = [];
        GuzzleHttp\set_path($c, 'foo/bar/[]', 'a');
        GuzzleHttp\set_path($c, 'foo/bar/[]', 'b');
        $this->assertEquals(['a', 'b'], $c['foo']['bar']);
    }

    public function testCanSetASingleElementPath()
    {
        $c = [];
        GuzzleHttp\set_path($c, 'foo', 'a');
        $this->assertEquals('a', $c['foo']);
    }

    public function testExpandsTemplate()
    {
        $this->assertEquals(
            'foo/123',
            GuzzleHttp\uri_template('foo/{bar}', ['bar' => '123'])
        );
    }
    public function noBodyProvider()
    {
        return [['get'], ['head'], ['delete']];
    }

    public function testProvidesDefaultUserAgent()
    {
        $ua = GuzzleHttp\default_user_agent();
        $this->assertEquals(1, preg_match('#^GuzzleHttp/.+ curl/.+ PHP/.+$#', $ua));
    }

    public function typeProvider()
    {
        return [
            ['foo', 'string(3) "foo"'],
            [true, 'bool(true)'],
            [false, 'bool(false)'],
            [10, 'int(10)'],
            [1.0, 'float(1)'],
            [new StrClass(), 'object(GuzzleHttp\Test\StrClass)'],
            [['foo'], 'array(1)']
        ];
    }
    /**
     * @dataProvider typeProvider
     */
    public function testDescribesType($input, $output)
    {
        $this->assertEquals($output, GuzzleHttp\describe_type($input));
    }

    public function testParsesHeadersFromLines()
    {
        $lines = ['Foo: bar', 'Foo: baz', 'Abc: 123', 'Def: a, b'];
        $this->assertEquals([
            'Foo' => ['bar', 'baz'],
            'Abc' => ['123'],
            'Def' => ['a, b'],
        ], GuzzleHttp\headers_from_lines($lines));
    }

    public function testParsesHeadersFromLinesWithMultipleLines()
    {
        $lines = ['Foo: bar', 'Foo: baz', 'Foo: 123'];
        $this->assertEquals([
            'Foo' => ['bar', 'baz', '123'],
        ], GuzzleHttp\headers_from_lines($lines));
    }

    public function testReturnsDebugResource()
    {
        $this->assertTrue(is_resource(GuzzleHttp\get_debug_resource()));
    }

    public function testProvidesDefaultCaBundler()
    {
        $this->assertFileExists(GuzzleHttp\default_ca_bundle());
    }
}

final class StrClass
{
    public function __toString()
    {
        return 'foo';
    }
}
