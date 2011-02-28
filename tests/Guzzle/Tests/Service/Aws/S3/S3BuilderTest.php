<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class S3BuilderTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\S3Builder::build
     * @covers Guzzle\Service\Aws\S3\S3Builder::setDevPayTokens
     * @covers Guzzle\Service\Aws\S3\S3Builder::getClass
     * @covers Guzzle\Service\Aws\AbstractBuilder
     */
    public function testBuild()
    {
        $builder = $this->getServiceBuilder()->getBuilder('test.s3');

        // Set some DevPay tokens to have the builder add the DevPay filter
        $this->assertSame($builder, $builder->setDevPayTokens('123', 'abc'));

        $this->assertInstanceOf('Guzzle\Service\Aws\S3\S3Builder', $builder);
        $this->assertEquals('Guzzle\\Service\\Aws\\S3\\S3Client', $builder->getClass());
        $this->assertEquals('test.s3', $builder->getName());

        // Make sure the builder creates a valid client objects
        $client = $builder->build();
        $this->assertInstanceOf('Guzzle\\Service\\Aws\\S3\\S3Client', $client);

        // Make sure the signing plugin was attached
        $this->assertTrue($client->hasPlugin('Guzzle\Service\Aws\S3\SignS3RequestPlugin'));

        // Make sure the builder added the Authentication filter for preparing requests
        $request = $client->getRequest('GET');
        $this->assertTrue($request->getPrepareChain()->hasFilter('Guzzle\\Service\\Aws\\S3\\Filter\\AddAuthHeader'));

        // Make sure the builder adds the DevPay token filter when preparing requests
        $this->assertTrue($request->getPrepareChain()->hasFilter('Guzzle\\Service\\Aws\\S3\\Filter\\DevPayTokenHeaders'));
    }

    /**
     * @covers Guzzle\Service\Aws\AbstractBuilder
     */
    public function testAbstractBuilder()
    {
        $builder = $this->getServiceBuilder()->getBuilder('test.s3');

        $this->assertSame($builder, $builder->setAuthentication('123', 'abc'));
        $this->assertSame($builder, $builder->setVersion('1'));
        $this->assertSame($builder, $builder->setSignature(new \Guzzle\Service\Aws\S3\S3Signature('123', 'abc')));
    }
}