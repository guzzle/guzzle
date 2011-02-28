<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\GetReport
 * @covers Guzzle\Service\Aws\Mws\command\AbstractMwsCommand
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class GetReportTest extends GuzzleTestCase
{
    public function testGetReport()
    {
        // Get client
        $client = $this->getServiceBuilder()->getClient('test.mws');

        // Get command
        $command = $client->getCommand('get_report')
            ->setReportId(12345);
        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\GetReport', $command);

        // Get mock response
        $this->setMockResponse($client, 'GetReportResponse');
        $report = $client->execute($command);
        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Model\CsvReport', $report);

        // Should have 3 rows in report
        $this->assertEquals(3, $report->count());

        // Report should have valid rows
        foreach($report as $row) {
            $this->assertArrayHasKey('item-name', $row);
        }

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('GetReport', $qs->get('Action'));
        $this->assertEquals('12345', $qs->get('ReportId'));
    }
}