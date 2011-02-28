<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;
use Guzzle\Service\Aws\Mws\Type;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\GetReportRequestList
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class GetReportRequestListText extends GuzzleTestCase
{
    public function testGetReportRequestList()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'GetReportRequestListResponse');

        $command = $client->getCommand('get_report_request_list')
            ->setReportRequestIdList(array(
                12345
            ))
            ->setReportTypeList(array(
                Type\ReportType::MERCHANT_LISTINGS_REPORT
            ))
            ->setReportProcessingStatusList(array(
                Type\FeedProcessingStatus::DONE
            ))
            ->setMaxCount(10)
            ->setRequestedFromDate(new \DateTime('2011-01-01'))
            ->setRequestedToDate(new \DateTime());

        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\GetReportRequestList', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('GetReportRequestList', $qs->get('Action'));
        $this->assertEquals('12345', $qs->get('ReportRequestIdList.Id.1'));
        $this->assertEquals('_GET_MERCHANT_LISTINGS_DATA_', $qs->get('ReportTypeList.Type.1'));
        $this->assertEquals('_DONE_', $qs->get('ReportProcessingStatusList.Status.1'));
        $this->assertEquals('10', $qs->get('MaxCount'));
        $this->assertArrayHasKey('RequestedFromDate', $qs->getAll());
        $this->assertArrayHasKey('RequestedToDate', $qs->getAll());
    }
}
