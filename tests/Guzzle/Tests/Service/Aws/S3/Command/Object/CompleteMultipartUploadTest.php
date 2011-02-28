<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Object;

use Guzzle\Service\Aws\S3\Command\Object\CompleteMultipartUpload;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class CompleteMultipartUploadTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\CompleteMultipartUpload
     */
    public function testComplete()
    {
        $parts = array(
            array(
                'part_number' => '1',
                'etag' => 'a54357aff0632cce46d942af68356b38'
            ),
            array(
                'part_number' => '2',
                'etag' => '0c78aef83f66abc1fa1e8477f296d394'
            ),
            array(
                'part_number' => '3',
                'etag' => '"acbd18db4cc2f85cedef654fccc4a4d8"'
            )
        );

        $command = new CompleteMultipartUpload();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setKey('key'));
        $this->assertSame($command, $command->setUploadId('123'));
        $this->assertSame($command, $command->setParts($parts));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'CompleteMultipartUploadResponse');
        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/key?uploadId=123', $command->getRequest()->getUrl());
        $this->assertEquals('POST', $command->getRequest()->getMethod());

        $this->assertEquals('<CompleteMultipartUpload>' . 
            '<Part><PartNumber>1</PartNumber><ETag>"a54357aff0632cce46d942af68356b38"</ETag></Part>' . 
            '<Part><PartNumber>2</PartNumber><ETag>"0c78aef83f66abc1fa1e8477f296d394"</ETag></Part>' . 
            '<Part><PartNumber>3</PartNumber><ETag>"acbd18db4cc2f85cedef654fccc4a4d8"</ETag></Part>' . 
            '</CompleteMultipartUpload>', (string)$command->getRequest()->getBody());
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\CompleteMultipartUpload
     * @expectedException Guzzle\Http\Message\BadResponseException
     */
    public function testCompleteFailed()
    {
        $parts = array(
            array(
                'part_number' => '1',
                'etag' => 'a54357aff0632cce46d942af68356b38'
            )
        );

        $command = new CompleteMultipartUpload();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setKey('key'));
        $this->assertSame($command, $command->setUploadId('123'));
        $this->assertSame($command, $command->setParts($parts));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'CompleteMultipartUploadBadResponse');
        $client->execute($command);
    }
}