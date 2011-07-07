<?php

namespace Guzzle\Tests;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class GuzzleTest extends GuzzleTestCase
{
    /**
     * @covers Guzzle\Guzzle
     */
    public function testGetDefaultUserAgent()
    {
        Guzzle::reset();
        $version = curl_version();
        $agent = sprintf('Guzzle/%s (Language=PHP/%s; curl=%s; Host=%s)', Guzzle::VERSION, \PHP_VERSION, $version['version'], $version['host']);
        $this->assertEquals($agent, Guzzle::getDefaultUserAgent());
        // Get it from cache this time
        $this->assertEquals($agent, Guzzle::getDefaultUserAgent());
    }

    /**
     * @covers Guzzle\Guzzle::getHttpDate
     */
    public function testGetHttpDate()
    {
        $fmt = 'D, d M Y H:i:s \G\M\T';
        $this->assertEquals(gmdate($fmt), Guzzle::getHttpDate('now'));
        $this->assertEquals(gmdate($fmt), Guzzle::getHttpDate(strtotime('now')));
        $this->assertEquals(gmdate($fmt, strtotime('+1 day')), Guzzle::getHttpDate('+1 day'));
    }

    public function dataProvider()
    {
        return array(
            array('this_is_a_test', '{{ a }}_is_a_{{ b }}', array(
                'a' => 'this',
                'b' => 'test'
            )),
            array('this_is_a_test', '{{abc}}_is_a_{{ 0 }}', array(
                'abc' => 'this',
                0 => 'test'
            )),
            array('this_is_a_test', '{{ abc }}_is_{{ not_found }}a_{{ 0 }}', array(
                'abc' => 'this',
                0 => 'test'
            )),
            array('this_is_a_test', 'this_is_a_test', array(
                'abc' => 'this'
            )),
            array('_is_a_', '{{ abc }}_is_{{ not_found }}a_{{ 0 }}', array()),
        );
    }

    /**
     * @covers Guzzle\Guzzle::inject
     * @dataProvider dataProvider
     */
    public function testInjectsConfigData($output, $input, $config)
    {
        $this->assertEquals($output, Guzzle::inject($input, new Collection($config)));
    }

    /**
     * @covers Guzzle\Guzzle::getCurlInfo
     */
    public function testCachesCurlInfo()
    {
        Guzzle::reset();
        $c = curl_version();
        $info = Guzzle::getCurlInfo();
        $this->assertInternalType('array', $info);
        $this->assertEquals(false, Guzzle::getCurlInfo('ewfewfewfe'));
        $this->assertEquals($c['version'], Guzzle::getCurlInfo('version'));
        $this->assertSame(Guzzle::getCurlInfo(), $info);
    }

    /**
     * @covers Guzzle\Guzzle::getCurlInfo
     * @covers Guzzle\Guzzle::reset
     */
    public function testDeterminesIfCurlCanFollowLocation()
    {
        Guzzle::reset();
        if (!ini_get('open_basedir')) {
            $this->assertTrue(Guzzle::getCurlInfo('follow_location'));
        } else {
            $this->assertFalse(Guzzle::getCurlInfo('follow_location'));
        }
    }
}