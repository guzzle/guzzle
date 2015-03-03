<?php
namespace GuzzleHttp\Tests;

use GuzzleHttp\Utils;

class UtilsTest extends \PHPUnit_Framework_TestCase
{
    public function testExpandsTemplate()
    {
        $this->assertEquals(
            'foo/123',
            Utils::uriTemplate('foo/{bar}', ['bar' => '123'])
        );
    }

    public function noBodyProvider()
    {
        return [['get'], ['head'], ['delete']];
    }

    public function testJsonDecodes()
    {
        $this->assertTrue(Utils::jsonDecode('true'));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Unable to parse JSON data: JSON_ERROR_SYNTAX - Syntax error, malformed JSON
     */
    public function testJsonDecodesWithErrorMessages()
    {
        Utils::jsonDecode('!narf!');
    }

    public function testProvidesDefaultUserAgent()
    {
        $ua = Utils::getDefaultUserAgent();
        $this->assertEquals(1, preg_match('#^Guzzle/.+ curl/.+ PHP/.+$#', $ua));
    }
}
