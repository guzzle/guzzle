<?php

namespace GuzzleHttp\Tests\Message;

use GuzzleHttp\Stream\Stream;
use GuzzleHttp\Message\MultipartStreamIterator;
use GuzzleHttp\Message\MultipartResponseIterator;

/**
 * @covers \GuzzleHttp\Message\AbstractMessage
 */
class MultipartResponseIteratorTest extends \PHPUnit_Framework_TestCase
{
    private $glue  = "\r\n";

    public function createResponseWithSiblings()
    {
        $part1 = implode($this->glue,[
            'Content-Type: application/json',
            'Link: </buckets/test_bucket>; rel="up"',
            'Etag: 5QqlA6qFh2Z88mxEDN5edh',
            'Last-Modified: Wed, 07 Jan 2015 19:41:52 GMT',
            '',
            '{"value":"[1,1,1]"}'
        ]);

        $part2 = implode($this->glue,[
            'Content-Type: application/json',
            'Link: </buckets/test_bucket>; rel="up"',
            'Etag: 3JmLc4m8r37FL6R89fJoJr',
            'Last-Modified: Wed, 07 Jan 2015 19:41:52 GMT',
            '',
            '{"value":"[2,2,2]"}'
        ]);

        $content = implode($this->glue, array_merge(
            [''],
            ['--KQLFjHN3yt2P0CWSxcIywUeI0kR'],
            [$part1],
            ['--KQLFjHN3yt2P0CWSxcIywUeI0kR'],
            [$part2],
            ['--KQLFjHN3yt2P0CWSxcIywUeI0kR--'],
            ['']
        ));

        return [
            'boundary' => 'KQLFjHN3yt2P0CWSxcIywUeI0kR',
            'content'  => $content,
            'parts'    => [$part1, $part2]
        ];
    }

    public function testMultipartStreamIterator()
    {
        $data     = $this->createResponseWithSiblings();
        $boundary = $data['boundary'];
        $content  = $data['content'];
        $part1    = $data['parts'][0];
        $part2    = $data['parts'][1];
        $stream   = Stream::factory($content);
        $iterator = new MultipartStreamIterator($stream, $boundary);
        $values   = iterator_to_array($iterator);

        $this->assertCount(2, $values);
        $this->assertInstanceOf('GuzzleHttp\Stream\Stream', $values[0]);
        $this->assertInstanceOf('GuzzleHttp\Stream\Stream', $values[1]);

        $this->assertEquals($this->glue . $part1, (string) $values[0]);
        $this->assertEquals($this->glue . $part2, (string) $values[1]);
    }

    public function testMultipartResponseIterator()
    {
        $data     = $this->createResponseWithSiblings();
        $stream   = Stream::factory($data['content']);
        $response = $this->getMock('GuzzleHttp\Message\ResponseInterface');

        $response->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);

        $response->expects($this->once())
            ->method('getHeader')
            ->with($this->equalTo('Content-Type'))
            ->willReturn('boundary=KQLFjHN3yt2P0CWSxcIywUeI0kR');

        $response->expects($this->once())
            ->method('getBody')
            ->willReturn($stream);

        $iterator = new MultipartResponseIterator($response);
        $values   = iterator_to_array($iterator);

        $this->assertCount(2, $values);
        $this->assertInstanceOf('GuzzleHttp\Message\ResponseInterface', $values[0]);
        $this->assertInstanceOf('GuzzleHttp\Message\ResponseInterface', $values[1]);
        $this->assertEquals('{"value":"[1,1,1]"}', $values[0]->getBody());
        $this->assertEquals('{"value":"[2,2,2]"}', $values[1]->getBody());
        $this->assertEquals('5QqlA6qFh2Z88mxEDN5edh', $values[0]->getHeader('Etag'));
        $this->assertEquals('3JmLc4m8r37FL6R89fJoJr', $values[1]->getHeader('Etag'));
        $this->assertEquals('application/json', $values[0]->getHeader('Content-Type'));
        $this->assertEquals('application/json', $values[1]->getHeader('Content-Type'));
        $this->assertEquals('</buckets/test_bucket>; rel="up"', $values[0]->getHeader('Link'));
        $this->assertEquals('</buckets/test_bucket>; rel="up"', $values[1]->getHeader('Link'));
        $this->assertEquals('Wed, 07 Jan 2015 19:41:52 GMT', $values[0]->getHeader('Last-Modified'));
        $this->assertEquals('Wed, 07 Jan 2015 19:41:52 GMT', $values[1]->getHeader('Last-Modified'));
    }
}