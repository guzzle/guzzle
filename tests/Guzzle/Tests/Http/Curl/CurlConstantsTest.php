<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http\Curl;

use Guzzle\Http\Curl\CurlConstants;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CurlConstantsTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Curl\CurlConstants
     */
    public function testTranslatesCurlOptionsAndValues()
    {
        $this->assertNotEmpty(CurlConstants::getOptions());
        $this->assertInternalType('array', CurlConstants::getOptions());

        $this->assertNotEmpty(CurlConstants::getValues());
        $this->assertInternalType('array', CurlConstants::getValues());

        $this->assertEquals('CURLOPT_FOLLOWLOCATION', CurlConstants::getOptionName(52));
        $this->assertEquals(52, CurlConstants::getOptionInt('CURLOPT_FOLLOWLOCATION'));
        $this->assertFalse(CurlConstants::getOptionInt('abc'));
        $this->assertFalse(CurlConstants::getOptionName(-100));

        $this->assertEquals('CURLAUTH_DIGEST', CurlConstants::getValueName(2));
        $this->assertEquals(2, CurlConstants::getValueInt('CURLAUTH_DIGEST'));
        $this->assertFalse(CurlConstants::getValueInt('abc'));
        $this->assertFalse(CurlConstants::getValueName(-100));
    }
}