<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get next page of GetReportRequestList
 *
 * Documentation:
 * The GetReportRequestListByNextToken operation returns a list of report requests that match
 * the query parameters, using the NextToken, which was supplied by a previous call to either
 * GetReportRequestListByNextToken or a call to GetReportRequestList, where the value of
 * HasNext was true in that previous call.
 *
 * Calls to GetReportRequestListByNextToken do not have a specific limitation, but are
 * included in the overall limit of 1,000 requests per hour per seller account.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle next_token doc="Token from the original GetReportRequestList call"
 */
class GetReportRequestListByNextToken extends AbstractMwsCommand
{
   protected $action = 'GetReportRequestListByNextToken';

   /**
    * Set next token
    *
    * @param string $nextToken
    *
    * @return GetReportRequestListByNextToken
    */
   public function setNextToken($nextToken)
   {
       return $this->set('next_token', $nextToken);
   }
}