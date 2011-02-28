<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get next page of results for GetFeedSubmissionList
 *
 * Documentation:
 * The GetFeedSubmissionListByNextToken operation returns a list of feed submissions that match
 * the query parameters, using the NextToken, which was supplied by a previous call to either
 * GetFeedSubmissionListByNextToken or a call to GetFeedSubmissionList, where the value of
 * HasNext was true in that previous call.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle next_token doc="Token from the original GetFeedSubmissionList request"
 */
class GetFeedSubmissionListByNextToken extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetFeedSubmissionListByNextToken';

    /**
     * Set next token
     *
     * @param string $nextToken
     *
     * @return GetFeedSubmissionListByNextToken 
     */
    public function setNextToken($nextToken)
    {
        return $this->set('next_token', $nextToken);
    }
}