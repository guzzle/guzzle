<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Object;

use Guzzle\Http\Message\Request;
use Guzzle\Guzzle;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CopyObjectTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\CopyObject
     */
    public function testCopyObject()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Object\CopyObject();

        $command->setBucket('test')->setKey('key');
        $this->assertSame($command, $command->setCopySource('source_bucket', 'source_key'));
        $this->assertSame($command, $command->setAcl(\Guzzle\Service\Aws\S3\S3Client::ACL_PUBLIC_READ));
        $this->assertSame($command, $command->setStorageClass('STANDARD'));
        $this->assertSame($command, $command->setMetadataDirective('COPY'));

        $this->assertSame($command, $command->setCopySourceIfMatch('match_etag'));
        $this->assertSame($command, $command->setCopySourceIfNoneMatch('none_match_etag'));

        $this->assertSame($command, $command->setCopySourceIfModifiedSince('now'));
        $this->assertSame($command, $command->setCopySourceIfUnmodifiedSince('now'));
        
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'CopyObjectResponse');
        $client->execute($command);

        $request = (string)$command->getRequest();
        $this->assertEquals('http://test.s3.amazonaws.com/key', $command->getRequest()->getUrl());
        $this->assertEquals('PUT', $command->getRequest()->getMethod());

        $this->assertFalse($this->compareHttpHeaders(array(
            'Host' => 'test.s3.amazonaws.com',
            'Date' => '*',
            'Content-Length' => '4',
            'Content-MD5' =>  '8d777f385d3dfec8815d20f7496026dc',
            'Authorization' => '*',
            'x-amz-test' => '123',
            'x-amz-acl' => 'public-read',
            'x-amz-storage-class' => 'STANDARD',
            'x-amz-copy-source' => '/source_bucket/source_key',
            'x-amz-metadata-directive' => 'COPY',
            'x-amz-copy-source-if-match' => 'match_etag',
            'x-amz-copy-source-if-none-match' => 'none_match_etag',
            'x-amz-copy-source-if-modified-since' => Guzzle::getHttpDate('now'),
            'x-amz-copy-source-if-unmodified-since' => Guzzle::getHttpDate('now'),
            'User-Agent' => Guzzle::getDefaultUserAgent()
        ), $command->getRequestHeaders()->getAll()));
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\CopyObject::setStorageClass
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage $storageClass must be one of STANDARD or REDUCED_REDUNDANCY
     */
    public function testValidatesStorageClass()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Object\CopyObject();
        $command->setStorageClass('error');
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\CopyObject::setMetadataDirective
     * @expectedException InvalidArgumentException
     * @expectedExceptionMessage $directive must be one of COPY or REPLACE
     */
    public function testValidatesMetadataDirective()
    {
        $command = new \Guzzle\Service\Aws\S3\Command\Object\CopyObject();
        $command->setMetadataDirective('error');
    }
}