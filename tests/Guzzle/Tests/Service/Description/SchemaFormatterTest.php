<?php

namespace Guzzle\Tests\Service\Description;

use Guzzle\Service\Description\SchemaFormatter;

/**
 * @covers Guzzle\Service\Description\SchemaFormatter
 */
class SchemaFormatterTest extends \Guzzle\Tests\GuzzleTestCase
{
    public function dateTimeProvider()
    {
        $d = 'October 13, 2012 16:15:46 UTC';

        return array(
            array('foo', 'does-not-exist', 'foo'),
            array($d, 'date-time', '2012-10-13T16:15:46Z'),
            array($d, 'date-time-http', 'Sat, 13 Oct 2012 16:15:46 GMT'),
            array($d, 'date', '2012-10-13'),
            array($d, 'timestamp', strtotime($d)),
            array(new \DateTime($d), 'timestamp', strtotime($d)),
            array($d, 'time', '16:15:46'),
            array(strtotime($d), 'time', '16:15:46'),
            array(strtotime($d), 'timestamp', strtotime($d)),
            array('true', 'boolean-string', 'true'),
            array(true, 'boolean-string', 'true'),
            array('false', 'boolean-string', 'false'),
            array(false, 'boolean-string', 'false')
        );
    }

    /**
     * @dataProvider dateTimeProvider
     */
    public function testFilters($value, $format, $result)
    {
        $this->assertEquals($result, SchemaFormatter::format($format, $value));
    }

    /**
     * @expectedException \Guzzle\Common\Exception\InvalidArgumentException
     */
    public function testValidatesDateTimeInput()
    {
        SchemaFormatter::format('date-time', false);
    }
}
