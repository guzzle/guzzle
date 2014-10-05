<?php

namespace GuzzleHttp\tests\Exception;

use GuzzleHttp\Exception\XmlParseException;

/**
 * @covers GuzzleHttp\Exception\XmlParseException
 */
class XmlParseExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testHasError()
    {
        $error = new \LibXMLError();
        $e = new XmlParseException('foo', null, null, $error);
        $this->assertSame($error, $e->getError());
        $this->assertEquals('foo', $e->getMessage());
    }
}
