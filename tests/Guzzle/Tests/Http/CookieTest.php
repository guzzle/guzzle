<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Http;

use Guzzle\Http\Cookie;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CookieTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * Data provider for tests
     *
     * @return array
     */
    public function provider()
    {
        return array(
            array('name=value', array(
                'name' => 'value'
            )),
            array('name=value;name2=value%202', array(
                'name' => 'value',
                'name2' => 'value 2'
            )),
            array('name=value;name2=x%3Dy%26a%3Db', array(
                'name' => 'value',
                'name2' => 'x=y&a=b'
            )),
        );
    }

    /**
     * @covers Guzzle\Http\Cookie::factory
     * @dataProvider provider
     */
    public function testFactoryBuildsCookiesFromCookieStrings($cookieString, array $data)
    {
        $jar = Cookie::factory($cookieString);
        $this->assertEquals($data, $jar->getAll());
    }

    /**
     * @covers Guzzle\Http\Cookie::__construct
     */
    public function testConstructorSetsDefaults()
    {
        $jar = new Cookie();
        $this->assertEquals(';', $jar->getFieldSeparator());
        $this->assertEquals('=', $jar->getValueSeparator());
        $this->assertEquals(true, $jar->isEncodingFields());
        $this->assertEquals(true, $jar->isEncodingValues());
        $this->assertEquals('', $jar->getPrefix());
    }

    /**
     * @covers Guzzle\Http\QueryString::__toString
     * @dataProvider provider
     */
    public function testConvertsToString($cookieString, array $data)
    {
        $jar = Cookie::factory($cookieString);
        $this->assertEquals($cookieString, (string)$jar);
    }
}