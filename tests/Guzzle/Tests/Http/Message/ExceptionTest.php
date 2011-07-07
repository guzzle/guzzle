<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\RequestException;
use Guzzle\Http\Message\BadResponseException;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class ExceptionTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Message\RequestException
     */
    public function testRequestException()
    {
        $e = new RequestException('Message');
        $request = new Request('GET', 'http://www.guzzle-project.com/');
        $e->setRequest($request);
        $this->assertEquals($request, $e->getRequest());
    }

    /**
     * @covers Guzzle\Http\Message\BadResponseException
     */
    public function testBadResponseException()
    {
        $e = new BadResponseException('Message');
        $response = new Response(200);
        $e->setResponse($response);
        $this->assertEquals($response, $e->getResponse());
    }
}