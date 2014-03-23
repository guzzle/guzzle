<?php

namespace GuzzleHttp\Tests;

use GuzzleHttp\Message\Response;

class FunctionsTest extends \PHPUnit_Framework_TestCase
{
    public function testExpandsTemplate()
    {
        $this->assertEquals('foo/123', \GuzzleHttp\uri_template('foo/{bar}', ['bar' => '123']));
    }

    public function noBodyProvider()
    {
        return [['get'], ['head'], ['delete']];
    }

    /**
     * @dataProvider noBodyProvider
     */
    public function testSendsNoBody($method)
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        call_user_func("GuzzleHttp\\{$method}", Server::$url, [
            'headers' => ['foo' => 'bar'],
            'query' => ['a' => '1']
        ]);
        $sent = Server::received(true)[0];
        $this->assertEquals(strtoupper($method), $sent->getMethod());
        $this->assertEquals('/?a=1', $sent->getResource());
        $this->assertEquals('bar', $sent->getHeader('foo'));
    }

    public function testSendsOptionsRequest()
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        \GuzzleHttp\options(Server::$url, ['headers' => ['foo' => 'bar']]);
        $sent = Server::received(true)[0];
        $this->assertEquals('OPTIONS', $sent->getMethod());
        $this->assertEquals('/', $sent->getResource());
        $this->assertEquals('bar', $sent->getHeader('foo'));
    }

    public function hasBodyProvider()
    {
        return [['put'], ['post'], ['patch']];
    }

    /**
     * @dataProvider hasBodyProvider
     */
    public function testSendsWithBody($method)
    {
        Server::flush();
        Server::enqueue([new Response(200)]);
        call_user_func("GuzzleHttp\\{$method}", Server::$url, [
            'headers' => ['foo' => 'bar'],
            'body'    => 'test',
            'query'   => ['a' => '1']
        ]);
        $sent = Server::received(true)[0];
        $this->assertEquals(strtoupper($method), $sent->getMethod());
        $this->assertEquals('/?a=1', $sent->getResource());
        $this->assertEquals('bar', $sent->getHeader('foo'));
        $this->assertEquals('test', $sent->getBody());
    }

    /**
     * @expectedException \PHPUnit_Framework_Error_Deprecated
     * @expectedExceptionMessage GuzzleHttp\Tests\HasDeprecations::baz() is deprecated and will be removed in a future version. Update your code to use the equivalent GuzzleHttp\Tests\HasDeprecations::foo() method instead to avoid breaking changes when this shim is removed.
     */
    public function testManagesDeprecatedMethods()
    {
        $d = new HasDeprecations();
        $d->baz();
    }

    /**
     * @expectedException \BadMethodCallException
     */
    public function testManagesDeprecatedMethodsAndHandlesMissingMethods()
    {
        $d = new HasDeprecations();
        $d->doesNotExist();
    }
}

class HasDeprecations
{
    function foo()
    {
        return 'abc';
    }
    function __call($name, $arguments)
    {
        return \GuzzleHttp\deprecation_proxy($this, $name, $arguments, [
            'baz' => 'foo'
        ]);
    }
}
