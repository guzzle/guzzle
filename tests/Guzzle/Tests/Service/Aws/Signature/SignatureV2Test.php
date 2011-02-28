<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Signature;

use Guzzle\Service\Aws\Signature\SignatureV2;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SignatureV2Test extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var SignatureV2
     */
    private $signature;

    /**
     * @var array
     */
    private $_options = array(
        'method' => 'GET',
        'endpoint' => 'http://test.amazonaws.com/'
    );

    /**
     * Prepares the environment before running a test.
     */
    protected function setUp()
    {
        $this->signature = new SignatureV2('access_key', 'secret');
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV2::calculateStringToSign
     */
    public function testCalculateStringToSignReturnsEmptyString()
    {
        $params = array('A' => 'v1');
        $this->assertEmpty($this->signature->calculateStringToSign($params));
        $this->assertEmpty($this->signature->calculateStringToSign($params, array()));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV2::calculateStringToSign
     */
    public function testCalculateStringToSignAlternateSort()
    {
        $params = array(
            'A' => 'v1',
            'b' => 'v2',
            'a' => 'v3'
        );
        
        $this->assertEquals("GET\ntest.amazonaws.com\n/\nA=v1&a=v3&b=v2", $this->signature->calculateStringToSign($params, array(
            // 'method' => 'GET', // Will automatically assume GET
            'endpoint' => 'http://test.amazonaws.com/',
            'sortMethod' => 'strcmp'
        )));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV2::calculateStringToSign
     */
    public function testCalculateStringToSignIgnoreVariable()
    {
        $params = array(
            'a' => 'v1',
            'b' => 'v2',
            'c' => 'v3'
        );
        
        $this->assertEquals("PUT\ntest.amazonaws.com\n/\na=v1&b=v2", $this->signature->calculateStringToSign($params, array(
            'method' => 'PUT',
            'endpoint' => 'https://test.amazonaws.com',
            'ignore' => 'c'
        )));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV2::calculateStringToSign
     */
    public function testCalculateStringToSignNullParameters()
    {
        $params = array(
            'a' => '',
            'b' => 'v2',
            'c' => 'v3'
        );
        
        $this->assertEquals("GET\ntest.amazonaws.com\n/\nb=v2&c=v3", $this->signature->calculateStringToSign($params, $this->_options));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV2::calculateStringToSign
     */
    public function testCalculateStringToSignEncode()
    {
        $params = array(
            'a' => 'test space',
            'b' => 'question?',
            'c' => '  v3'
        );
        
        $this->assertEquals("GET\ntest.amazonaws.com\n/\na=test%20space&b=question%3F&c=%20%20v3", $this->signature->calculateStringToSign($params, $this->_options));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV2::calculateStringToSign
     */
    public function testCalculateStringToSignEmptyRequest()
    {
        $this->assertEquals("GET\ntest.amazonaws.com\n/\n", $this->signature->calculateStringToSign(array(), $this->_options));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV2
     */
    public function testHashingAlgorithms()
    {
        $this->assertEquals('HmacSHA256', $this->signature->getAwsHashingAlgorithm());
        $this->assertEquals('sha256', $this->signature->getPhpHashingAlgorithm());
    }
}