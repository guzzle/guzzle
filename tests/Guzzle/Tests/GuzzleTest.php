<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests;

use Guzzle\Guzzle;

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
}