<?php
namespace GuzzleHttp\Test;

use GuzzleHttp;
use PHPUnit\Framework\TestCase;

class FunctionsTest extends TestCase
{
    public function testExpandsTemplate()
    {
        $this->assertSame(
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
        $this->assertRegExp('#^GuzzleHttp/.+ curl/.+ PHP/.+$#', $ua);
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
        $this->assertSame($output, GuzzleHttp\describe_type($input));
    }

    public function testParsesHeadersFromLines()
    {
        $lines = ['Foo: bar', 'Foo: baz', 'Abc: 123', 'Def: a, b'];
        $this->assertSame([
            'Foo' => ['bar', 'baz'],
            'Abc' => ['123'],
            'Def' => ['a, b'],
        ], GuzzleHttp\headers_from_lines($lines));
    }

    public function testParsesHeadersFromLinesWithMultipleLines()
    {
        $lines = ['Foo: bar', 'Foo: baz', 'Foo: 123'];
        $this->assertSame([
            'Foo' => ['bar', 'baz', '123'],
        ], GuzzleHttp\headers_from_lines($lines));
    }

    public function testReturnsDebugResource()
    {
        $this->assertInternalType('resource', GuzzleHttp\debug_resource());
    }

    public function testProvidesDefaultCaBundler()
    {
        $this->assertFileExists(GuzzleHttp\default_ca_bundle());
    }

    public function noProxyProvider()
    {
        return [
            ['mit.edu', ['.mit.edu'], false],
            ['foo.mit.edu', ['.mit.edu'], true],
            ['mit.edu', ['mit.edu'], true],
            ['mit.edu', ['baz', 'mit.edu'], true],
            ['mit.edu', ['', '', 'mit.edu'], true],
            ['mit.edu', ['baz', '*'], true],
        ];
    }

    /**
     * @dataProvider noproxyProvider
     */
    public function testChecksNoProxyList($host, $list, $result)
    {
        $this->assertSame(
            $result,
            \GuzzleHttp\is_host_in_noproxy($host, $list)
        );
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEnsuresNoProxyCheckHostIsSet()
    {
        \GuzzleHttp\is_host_in_noproxy('', []);
    }

    public function testEncodesJson()
    {
        $this->assertSame('true', \GuzzleHttp\json_encode(true));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testEncodesJsonAndThrowsOnError()
    {
        \GuzzleHttp\json_encode("\x99");
    }

    public function testDecodesJson()
    {
        $this->assertTrue(\GuzzleHttp\json_decode('true'));
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testDecodesJsonAndThrowsOnError()
    {
        \GuzzleHttp\json_decode('{{]]');
    }
}

final class StrClass
{
    public function __toString()
    {
        return 'foo';
    }
}
