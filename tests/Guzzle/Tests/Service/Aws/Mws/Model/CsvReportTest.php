<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Model;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Model\CsvReport;

/**
 * @covers Guzzle\Service\Aws\Mws\Model\CsvReport
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class CsvReportTest extends GuzzleTestCase
{
    public function test__construct()
    {
        $this->setExpectedException('InvalidArgumentException');
        $data = array(
            'A' => array(
                'A' => 1,
                'B' => 2,
                'C' => 3
            )
        );
        $report = new CsvReport($data);
    }

    public function testCsvReport()
    {
        // Valid tab-separated data
        $data = "A\tB\tC\n";
        $data .= "1\t2\t3\n";
        $data .= "4\t5\t6\n";

        $report = new CsvReport($data);

        // Expected parsed format
        $expected = array(
            array(
                'A' => 1,
                'B' => 2,
                'C' => 3
            ),
            array(
                'A' => 4,
                'B' => 5,
                'C' => 6
            )
        );
        
        $this->assertEquals($expected, $report->getRows());
        $this->assertEquals(2, $report->count());
        $this->assertInstanceOf('\ArrayIterator', $report->getIterator());

        $report = new CsvReport($expected);
        $this->assertEquals($expected, $report->getRows());
    }

    public function testBadCsvData()
    {
        $broken = "A\tB\n";
        $broken .= "1\n";
        $this->setExpectedException('UnexpectedValueException');
        $report = new CsvReport($broken);
    }

    public function testBadType()
    {
        $this->setExpectedException('InvalidArgumentException');
        $report = new CsvReport(false);
    }

    public function testFieldNames()
    {
        // Test getting field names when data array given
        $report = new CsvReport(array(
            array(
                'A' => 1,
                'B' => 2,
                'C' => 3
            )
        ));
        $this->assertEquals(array('A', 'B', 'C'), $report->getFieldNames());

        // Gest getting field names when string data given
        $report = new CsvReport("A\tB\tC" . PHP_EOL . "1\t2\t3");
        $this->assertEquals(array('A', 'B', 'C'), $report->getFieldNames());
    }

    public function testToString()
    {
        $report = new CsvReport(array(
            array(
                'A' => 1,
                'B' => 2
            )
        ));

        $this->assertEquals("A\tB" . PHP_EOL . "1\t2", $report->toString());
        $this->assertEquals("A\tB" . PHP_EOL . "1\t2", $report->__toString());
    }
}