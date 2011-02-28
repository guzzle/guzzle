<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\CancelReportRequests
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class CancelReportRequestsTest extends GuzzleTestCase
{
    public function testCancelReportRequests()
    {
        // Get client
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'CancelReportRequestsResponse');

        $command = $client->getCommand('cancel_report_requests')
            ->setReportRequestIdList(array(
                123
            ))
            ->setReportTypeList(array(
                Type\ReportType::MERCHANT_LISTINGS_REPORT
            ))
            ->setReportProcessingStatusList(array(
                Type\ReportProcessingStatus::DONE
            ))
            ->setRequestedFromDate(new \DateTime('2011-01-01'))
            ->setRequestedToDate(new \DateTime());
        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\CancelReportRequests', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('CancelReportRequests', $qs->get('Action'));
        $this->assertEquals('123', $qs->get('ReportRequestIdList.Id.1'));
        $this->assertEquals('_GET_MERCHANT_LISTINGS_DATA_', $qs->get('ReportTypeList.Type.1'));
        $this->assertEquals('_DONE_', $qs->get('ReportProcessingStatusList.Status.1'));
        $this->assertArrayHasKey('RequestedFromDate', $qs->getAll());
        $this->assertArrayHasKey('RequestedToDate', $qs->getAll());

    }
}