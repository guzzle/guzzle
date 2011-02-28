<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\GetReportScheduleListByNextToken
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class GetReportScheduleListByNextTokenTest extends GuzzleTestCase
{
    public function testGetReportScheduleListByNextToken()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'GetReportScheduleListByNextTokenResponse');

        $command = $client->getCommand('get_report_schedule_list_by_next_token')
            ->setNextToken('asdf');

        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\GetReportScheduleListByNextToken', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('GetReportScheduleListByNextToken', $qs->get('Action'));
        $this->assertEquals('asdf', $qs->get('NextToken'));
    }
}