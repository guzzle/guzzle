<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Service\Aws\Mws\Command;

use Guzzle\Tests\GuzzleTestCase;

/**
 * @covers Guzzle\Service\Aws\Mws\Command\GetReportRequestListByNextToken
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class GetReportRequestListByNextTokenTest extends GuzzleTestCase
{
    public function testGetReportRequestListByNextToken()
    {
        $client = $this->getServiceBuilder()->getClient('test.mws');

        $this->setMockResponse($client, 'GetReportRequestListByNextTokenResponse');

        $command = $client->getCommand('get_report_request_list_by_next_token')
            ->setNextToken('asdf');

        $this->assertInstanceOf('Guzzle\Service\Aws\Mws\Command\GetReportRequestListByNextToken', $command);

        $response = $client->execute($command);
        $this->assertInstanceOf('\SimpleXMLElement', $response);

        $qs = $command->getRequest()->getQuery();
        $this->assertEquals('GetReportRequestListByNextToken', $qs->get('Action'));
        $this->assertEquals('asdf', $qs->get('NextToken'));
    }
}