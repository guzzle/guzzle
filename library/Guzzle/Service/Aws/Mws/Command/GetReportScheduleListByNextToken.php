<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get next page of results from GetReportScheduleList
 *
 * Documentation:
 * The GetReportScheduleListByNextToken operation returns a list of report schedules that match
 * the query parameters, using the NextToken, which was supplied by a previous call to either
 * GetReportScheduleListByNextToken or a call to GetReportScheduleList, where the value of
 * HasNext was true in that previous call.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle next_token doc="NextToken value from previous GetReportScheduleList call" required="true"
 */
class GetReportScheduleListByNextToken extends AbstractMwsCommand
{
    protected $action = 'GetReportScheduleListByNextToken';

    /**
     * Set next token
     *
     * @param string $nextToken
     *
     * @return GetReportScheduleListByNextToken
     */
    public function setNextToken($nextToken)
    {
        return $this->set('next_token', $nextToken);
    }
}