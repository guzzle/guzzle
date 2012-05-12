<?php

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Http\Curl\CurlVersion;

/**
 * @covers Guzzle\Http\Curl\CurlVersion
 */
class CurlVersionTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function testCachesCurlInfo()
    {
        $info = curl_version();
        $info['follow_location'] = !ini_get('open_basedir');
        $instance = CurlVersion::getInstance();

        // Clear out the info cache
        $refObject = new \ReflectionObject($instance);
        $refProperty = $refObject->getProperty('version');
        $refProperty->setAccessible(true);
        $refProperty->setValue($instance, array());

        $this->assertEquals($info, $instance->getAll());
        $this->assertEquals($info, $instance->getAll());

        $this->assertEquals($info['version'], $instance->get('version'));
        $this->assertFalse($instance->get('foo'));
    }

    public function testDeterminesIfCurlCanFollowLocation()
    {
        if (!ini_get('open_basedir')) {
            $this->assertTrue(CurlVersion::getInstance()->get('follow_location'));
        } else {
            $this->assertFalse(CurlVersion::getInstance()->get('follow_location'));
        }
    }

    public function testIsSingleton()
    {
        $refObject = new \ReflectionClass('Guzzle\Http\Curl\CurlVersion');
        $refProperty = $refObject->getProperty('instance');
        $refProperty->setAccessible(true);
        $refProperty->setValue(null, null);

        $this->assertInstanceOf('Guzzle\Http\Curl\CurlVersion', CurlVersion::getInstance());
    }
}
