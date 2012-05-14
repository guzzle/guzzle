<?php

namespace Guzzle\Tests\Service;

use Guzzle\Service\JsonLoader;

/**
 * @covers Guzzle\Service\JsonLoader
 */
class JsonLoaderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @expectedException Guzzle\Service\Exception\JsonException
     * @expectedExceptionMessage Unable to open
     */
    public function testFileMustBeReadable()
    {
        $loader = new JsonLoader();
        $loader->parseJsonFile('fooooooo!');
    }

    /**
     * @expectedException Guzzle\Service\Exception\JsonException
     * @expectedExceptionMessage Error loading JSON data from
     */
    public function testJsonMustBeValue()
    {
        $loader = new JsonLoader();
        $loader->parseJsonFile(__FILE__);
    }

    public function testFactoryCanCreateFromJson()
    {
        $file = dirname(__DIR__) . '/TestData/services/json1.json';
        $loader = new JsonLoader();
        $data = $loader->parseJsonFile($file);

        $this->assertArrayHasKey('includes', $data);
        $this->assertArrayHasKey('services', $data);
        $this->assertInternalType('array', $data['services']['foo']);
        $this->assertInternalType('array', $data['services']['abstract']);
        $this->assertInternalType('array', $data['services']['mock']);
        $this->assertEquals('bar', $data['services']['foo']['params']['baz']);
    }
}
