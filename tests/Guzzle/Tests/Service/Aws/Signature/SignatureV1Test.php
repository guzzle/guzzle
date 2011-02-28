<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Signature;

use Guzzle\Service\Aws\Signature\SignatureV1;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SignatureV1Test extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @var SignatureV1
     */
    private $signature;

    /**
     * Prepares the environment before running a test.
     */
    protected function setUp()
    {
        $this->signature = new SignatureV1('access_key', 'secret');
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV1::calculateStringToSign
     */
    public function testCalculateStringToSignAlternateSort()
    {
        $params = array(
            'A' => 'v1',
            'b' => 'v2',
            'a' => 'v3'
        );
        $this->assertEquals('Av1av3bv2', $this->signature->calculateStringToSign($params, array(
            'sortMethod' => 'strcmp'
        )));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV1::calculateStringToSign
     */
    public function testCalculateStringToSignIgnoreVariable()
    {
        $params = array(
            'a' => 'v1',
            'b' => 'v2',
            'c' => 'v3'
        );
        $this->assertEquals('av1bv2', $this->signature->calculateStringToSign($params, array(
            'ignore' => 'c'
        )));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV1::calculateStringToSign
     */
    public function testCalculateStringToSignNullParameters()
    {
        $params = array(
            'a' => '',
            'b' => 'v2',
            'c' => 'v3'
        );
        $this->assertEquals('bv2cv3', $this->signature->calculateStringToSign($params));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV1::calculateStringToSign
     */
    public function testCalculateStringToSignEmptyRequest()
    {
        $this->assertEquals('', $this->signature->calculateStringToSign(array()));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\SignatureV1::getAwsHashingAlgorithm
     * @covers Guzzle\Service\Aws\Signature\SignatureV1::getPhpHashingAlgorithm
     */
    public function testHashingAlgorithms()
    {
        $this->assertEquals('HmacSHA1', $this->signature->getAwsHashingAlgorithm());
        $this->assertEquals('sha1', $this->signature->getPhpHashingAlgorithm());
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\AbstractSignature::__construct
     * @covers Guzzle\Service\Aws\Signature\AbstractSignature::getAccessKeyId
     * @covers Guzzle\Service\Aws\Signature\AbstractSignature::getSecretAccessKey
     * @covers Guzzle\Service\Aws\Signature\AbstractSignature::getVersion
     * @covers Guzzle\Service\Aws\Signature\AbstractSignature::signString
     */
    public function testAbstractSignature()
    {
        $signature = new SignatureV1('a', 's');

        $this->assertEquals('a', $signature->getAccessKeyId());
        $this->assertEquals('s', $signature->getSecretAccessKey());
        $this->assertEquals('1', $signature->getVersion());

        // Test signing a string
        $this->assertEquals('t+ODukdfc3usAF+HextTblYraxs=', $signature->signString('abc'));
        $this->assertEquals('6t9wEpYa6HJk9JwrM7mZbmsLhF4=', $signature->signString('abc' . chr(240)));
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\AbstractSignature::__construct
     * @expectedException Guzzle\Service\Aws\AwsException
     */
    public function testAbstractSignatureRequiresAccessKeyId()
    {
        $signature = new SignatureV1(null, 's');
    }

    /**
     * @covers Guzzle\Service\Aws\Signature\AbstractSignature::__construct
     * @expectedException Guzzle\Service\Aws\AwsException
     */
    public function testAbstractSignatureRequiresSecretAccessKey()
    {
        $signature = new SignatureV1('a', null);
    }
}