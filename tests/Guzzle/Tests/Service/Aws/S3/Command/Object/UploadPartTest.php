<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\S3\Command\Object;

use Guzzle\Service\Aws\S3\Command\Object\UploadPart;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class UploadPartTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\UploadPart
     */
    public function testUploadPart()
    {
        $command = new UploadPart();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setKey('key'));
        $this->assertSame($command, $command->setUploadId('123'));
        $this->assertSame($command, $command->setPartNumber(1));
        $this->assertSame($command, $command->setBody('data'));

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'UploadPartResponse');
        $client->execute($command);

        $request = (string)$command->getRequest();
        $this->assertEquals('http://test.s3.amazonaws.com/key?partNumber=1&uploadId=123', $command->getRequest()->getUrl());
        $this->assertEquals('PUT', $command->getRequest()->getMethod());
        $this->assertTrue($command->getRequestHeaders()->hasKey('Content-MD5'));
        $this->assertEquals('data', (string)$command->getRequest()->getBody());

        $this->assertEquals('b54357faf0632cce46e942fa68356b38', $command->getResult());
    }

    /**
     * @covers Guzzle\Service\Aws\S3\Command\Object\UploadPart
     */
    public function testUploadPartNoValidation()
    {
        $command = new UploadPart();
        $this->assertSame($command, $command->setBucket('test'));
        $this->assertSame($command, $command->setKey('key'));
        $this->assertSame($command, $command->setUploadId('123'));
        $this->assertSame($command, $command->setPartNumber(1));
        $this->assertSame($command, $command->setBody('data'));
        $this->assertSame($command, $command->disableChecksumValidation());

        $client = $this->getServiceBuilder()->getClient('test.s3');
        $this->setMockResponse($client, 'UploadPartResponse');
        $client->execute($command);

        $request = (string)$command->getRequest();
        $this->assertFalse($command->getRequestHeaders()->hasKey('Content-MD5'));
    }
}