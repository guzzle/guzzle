<?php

namespace Guzzle\Tests\Http;

use Guzzle\Common\Version;
use Guzzle\Http\Utils;

class UtilsTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Utils::getHttpDate
     */
    public function testGetHttpDate()
    {
        $fmt = 'D, d M Y H:i:s \G\M\T';
        $this->assertEquals(gmdate($fmt), Utils::getHttpDate('now'));
        $this->assertEquals(gmdate($fmt), Utils::getHttpDate(strtotime('now')));
        $this->assertEquals(gmdate($fmt, strtotime('+1 day')), Utils::getHttpDate('+1 day'));
    }

    /**
     * @covers Guzzle\Http\Utils::getDefaultUserAgent
     */
    public function testGetDefaultUserAgent()
    {
        // Clear out the user agent cache
        $refObject = new \ReflectionClass('Guzzle\Http\Utils');
        $refProperty = $refObject->getProperty('userAgent');
        $refProperty->setAccessible(true);
        $refProperty->setValue(null, null);

        $version = curl_version();
        $agent = sprintf('Guzzle/%s curl/%s PHP/%s', Version::VERSION, $version['version'], PHP_VERSION);
        $this->assertEquals($agent, Utils::getDefaultUserAgent());
        // Get it from cache this time
        $this->assertEquals($agent, Utils::getDefaultUserAgent());
    }
}
