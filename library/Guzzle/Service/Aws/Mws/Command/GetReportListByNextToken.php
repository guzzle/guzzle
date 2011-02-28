<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get next page of GetReportList results
 *
 * Documentation:
 * The GetReportListByNextToken operation returns a list of reports that match the query
 * parameters, using the NextToken, which was supplied by a previous call to either
 * GetReportListByNextToken or a call to GetReportList, where the value of HasNext was true in
 * that previous call.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle next_token doc="Token returned in a previous call to GetReportList" required="true"
 */
class GetReportListByNextToken extends AbstractMwsCommand
{
    protected $action = 'GetReportListByNextToken';

    /**
     * Set report next token
     *
     * @param string $nextToken
     *
     * @return GetReportListByNextToken
     */
    public function setNextToken($nextToken)
    {
        return $this->set('next_token', $nextToken);
    }
}