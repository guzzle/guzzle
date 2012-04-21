<?php

namespace Guzzle\Tests\Http\Message;

use Guzzle\Guzzle;
use Guzzle\Common\Collection;
use Guzzle\Http\Client;
use Guzzle\Http\EntityBody;
use Guzzle\Http\Message\Request;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\EntityEnclosingRequest;
use Guzzle\Http\QueryString;

/**
 * @group server
 */
class EntityEnclosingRequestTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::__construct
     */
    public function testConstructorConfiguresRequest()
    {
        $request = new EntityEnclosingRequest('PUT', 'http://test.com', array(
            'X-Test' => '123'
        ));
        $request->setBody('Test');
        $this->assertEquals('123', $request->getHeader('X-Test'));
        $this->assertEquals('100-Continue', $request->getHeader('Expect'));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::setBody
     */
    public function testCanSetBodyWithoutOverridingContentType()
    {
        $request = new EntityEnclosingRequest('PUT', 'http://test.com', array(
            'Content-Type' => 'application/json'
        ));
        $request->setBody('{"a":"b"}');
        $this->assertEquals('application/json', $request->getHeader('Content-Type'));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::__toString
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFields
     */
    public function testRequestIncludesBodyInMessage()
    {
        $request = RequestFactory::getInstance()->create('PUT', 'http://www.guzzle-project.com/', null, 'data');
        $this->assertEquals("PUT / HTTP/1.1\r\n"
            . "Host: www.guzzle-project.com\r\n"
            . "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n"
            . "Expect: 100-Continue\r\n"
            . "Content-Length: 4\r\n\r\n"
            . "data", (string) $request);
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::__toString
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFields
     */
    public function testAddsPostFieldsAndSetsContentLength()
    {
        $request = RequestFactory::getInstance()->create('POST', 'http://www.guzzle-project.com/', null, array(
            'data' => '123'
        ));
        $this->assertEquals("POST / HTTP/1.1\r\n"
            . "Host: www.guzzle-project.com\r\n"
            . "User-Agent: " . Guzzle::getDefaultUserAgent() . "\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n\r\n"
            . "data=123", (string) $request);
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::__toString
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFiles
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFields
     */
    public function testAddsPostFilesAndSetsContentType()
    {
        $request = RequestFactory::getInstance()->create('POST', 'http://www.test.com/')
            ->addPostFiles(array(
                'file' => __FILE__
            ))->addPostFields(array(
                'a' => 'b'
            ));
        $message = (string) $request;
        $this->assertEquals('multipart/form-data', $request->getHeader('Content-Type'));
        $this->assertEquals('100-Continue', $request->getHeader('Expect'));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::processPostFields
     */
    public function testRequestBodyContainsPostFiles()
    {
        $request = RequestFactory::getInstance()->create('POST', 'http://www.test.com/');
        $request->addPostFields(array(
            'test' => '123'
        ));
        $this->assertContains("\r\n\r\ntest=123", (string) $request);
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::processPostFields
     */
    public function testRequestBodyAddsContentLength()
    {
        $request = RequestFactory::getInstance()->create('PUT', 'http://www.test.com/');
        $request->setBody(EntityBody::factory('test'));
        $this->assertEquals(4, (string) $request->getHeader('Content-Length'));
        $this->assertFalse($request->hasHeader('Transfer-Encoding'));
    }

    /**
     * Tests using a Transfer-Encoding chunked entity body already set
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::processPostFields
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::setBody
     */
    public function testRequestBodyDoesNotUseContentLengthWhenChunked()
    {
        $request = RequestFactory::getInstance()->create('PUT', 'http://www.test.com/');
        $request->setBody(EntityBody::factory('test'), null, true);
        $this->assertNull($request->getHeader('Content-Length'));
        $this->assertTrue($request->hasHeader('Transfer-Encoding'));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getBody
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::setBody
     */
    public function testRequestHasMutableBody()
    {
        $request = RequestFactory::getInstance()->create('PUT', 'http://www.guzzle-project.com/', null, 'data');
        $body = $request->getBody();
        $this->assertInstanceOf('Guzzle\\Http\\EntityBody', $body);
        $this->assertSame($body, $request->getBody());

        $newBody = EntityBody::factory('foobar');
        $request->setBody($newBody);
        $this->assertEquals('foobar', (string) $request->getBody());
        $this->assertSame($newBody, $request->getBody());
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFields
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getPostFields
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getPostFiles
     */
    public function testSetPostFields()
    {
        $request = RequestFactory::getInstance()->create('POST', 'http://www.guzzle-project.com/');
        $this->assertInternalType('array', $request->getPostFields());

        $fields = new QueryString(array(
            'a' => 'b'
        ));
        $request->addPostFields($fields);
        $this->assertEquals($fields->getAll(), $request->getPostFields());
        $this->assertEquals(array(), $request->getPostFiles());
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getPostFiles
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFiles
     */
    public function testSetPostFiles()
    {
        $request = RequestFactory::getInstance()->create('POST', $this->getServer()->getUrl())
            ->setClient(new Client())
            ->addPostFiles(array(__FILE__))
            ->addPostFields(array(
                'test' => 'abc'
            ));

        $this->assertEquals(array(
            'file' => '@' . __FILE__,
            'test' => 'abc'
        ), $request->getPostFields());

        $this->assertEquals(array(
            'file' => __FILE__
        ), $request->getPostFiles());

        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request->send();

        $this->assertNotNull($request->getHeader('Content-Length'));
        $this->assertContains('multipart/form-data; boundary=', (string) $request->getHeader('Content-Type'), '-> cURL must add the boundary');
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::addPostFiles
     * @expectedException Guzzle\Http\Exception\RequestException
     */
    public function testSetPostFilesThrowsExceptionWhenFileIsNotFound()
    {
        $request = RequestFactory::getInstance()->create('POST', 'http://www.guzzle-project.com/')
            ->addPostFiles(array(
                'file' => 'filenotfound.ini'
            ));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::setPostField
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::processPostFields
     */
    public function testPostRequestsUseApplicationXwwwForUrlEncodedForArrays()
    {
        $request = RequestFactory::getInstance()->create('POST', 'http://www.guzzle-project.com/');
        $request->setPostField('a', 'b');
        $this->assertContains("\r\n\r\na=b", (string) $request);
        $this->assertEquals('application/x-www-form-urlencoded', $request->getHeader('Content-Type'));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::processPostFields
     */
    public function testProcessMethodAddsPostCurlOptions()
    {
        $request = RequestFactory::getInstance()->create('POST', 'http://www.guzzle-project.com/');
        $request->setPostField('a', 'b');
        $this->assertEquals('application/x-www-form-urlencoded', $request->getHeader('Content-Type'));
        $this->assertEquals('a=b', $request->getCurlOptions()->get(CURLOPT_POSTFIELDS));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::processPostFields
     */
    public function testPostRequestsUseMultipartFormDataWithFiles()
    {
        $request = RequestFactory::getInstance()->create('POST', 'http://www.guzzle-project.com/');
        $request->addPostFiles(array('file' => __FILE__));
        $this->assertEquals('multipart/form-data', $request->getHeader('Content-Type'));
        $this->assertEquals(array('file' => '@' . __FILE__), $request->getCurlOptions()->get(CURLOPT_POSTFIELDS));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::setBody
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::processPostFields
     */
    public function testCanSendMultipleRequestsUsingASingleRequestObject()
    {
        $this->getServer()->flush();
        $this->getServer()->enqueue(array(
            "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n",
            "HTTP/1.1 201 Created\r\nContent-Length: 0\r\n\r\n",
        ));

        // Send the first request
        $request = RequestFactory::getInstance()->create('PUT', $this->getServer()->getUrl())
            ->setBody('test')
            ->setClient(new Client());
        $request->send();
        $this->assertEquals(200, $request->getResponse()->getStatusCode());

        // Send the second request
        $request->setBody('abcdefg', 'application/json', false);
        $request->send();
        $this->assertEquals(201, $request->getResponse()->getStatusCode());

        // Ensure that the same request was sent twice with different bodies
        $requests = $this->getServer()->getReceivedRequests(true);
        $this->assertEquals(2, count($requests));
        $this->assertEquals(4, $requests[0]->getHeader('Content-Length', true));
        $this->assertEquals(7, $requests[1]->getHeader('Content-Length', true));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getPostField
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::removePostField
     */
    public function testRemovingPostFieldRebuildsPostFields()
    {
        $request = new EntityEnclosingRequest('POST', 'http://test.com');
        $request->setPostField('test', 'value');
        $request->removePostField('test');
        $this->assertNull($request->getPostField('test'));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::setBody
     */
    public function testUsesChunkedTransferWhenBodyLengthCannotBeDetermined()
    {
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request = new EntityEnclosingRequest('PUT', 'http://test.com/');
        $request->setBody(fopen($this->getServer()->getUrl(), 'r'));
        $this->assertEquals('chunked', $request->getHeader('Transfer-Encoding'));
        $this->assertFalse($request->hasHeader('Content-Length'));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::setBody
     * @expectedException Guzzle\Http\Exception\RequestException
     */
    public function testThrowsExceptionWhenContentLengthCannotBeDeterminedAndUsingHttp1()
    {
        $request = new EntityEnclosingRequest('PUT', 'http://test.com/');
        $this->getServer()->enqueue("HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
        $request->setProtocolVersion('1.0');
        $request->setBody(fopen($this->getServer()->getUrl(), 'r'));
    }

    /**
     * @covers Guzzle\Http\Message\EntityEnclosingRequest::getPostFiles
     */
    public function testAllowsNestedPostData()
    {
        $request = new EntityEnclosingRequest('POST', 'http://test.com/');
        $request->addPostFields(array(
            'a' => array('b', 'c')
        ));
        $this->assertEquals(array(
            'a' => array('b', 'c')
        ), $request->getPostFields());
    }
}
