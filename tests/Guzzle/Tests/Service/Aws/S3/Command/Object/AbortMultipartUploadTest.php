<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Object;

use Guzzle\Service\Aws\S3\Command\Object\AbortMultipartUpload;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AbortMultipartUploadTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\AbortMultipartUpload
     */
    public function testAbort()
    {
        $command = new AbortMultipartUpload();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setKey('key'));
        $this->assertSame($command, $command->setUploadId('123'));
        
        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'DefaultResponse');
        $client->execute($command);

        $this->assertEquals('http://test.s3.amazonaws.com/key?uploadId=123', $command->getRequest()->getUrl());
        $this->assertEquals('DELETE', $command->getRequest()->getMethod());
    }
}