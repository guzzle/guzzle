<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get the result of the given feed submission
 *
 * Documentation:
 * The GetFeedSubmissionResult operation returns the feed processing report and the Content-MD5
 * header for the returned body.
 *
 * Calls to GetFeedSubmissionResult are limited to 60 requests per hour, included within the
 * overall limit of 1,000 calls per seller account per hour.
 *
 * You should compute the MD5 hash of the HTTP body that we returned to you, and compare that
 * with the Content-MD5 header value that we returned. If they do not match, which means the
 * body was corrupted during transmission, you should discard the result and automatically
 * retry the call for up to three more times.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle feed_submission_id doc="The feed submission to get results for" required="true"
 */
class GetFeedSubmissionResult extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetFeedSubmissionResult';

    /**
     * Set feed submission ID
     *
     * @param int $feedSubmissionId
     *
     * @return GetFeedSubmissionResult
     */
    public function setFeedSubmissionId($feedSubmissionId)
    {
        return $this->set('feed_submission_id', $feedSubmissionId);
    }
}