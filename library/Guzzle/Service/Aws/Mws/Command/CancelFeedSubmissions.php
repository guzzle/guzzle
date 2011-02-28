<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Cancel one or more feed submissions
 *
 * Documentation:
 * The CancelFeedSubmissions operation cancels one or more feed submissions, returning the
 * count of the canceled feed submissions and the feed submission information. You can specify
 * a number to cancel of greater than one hundred, but information will only be returned about
 * the first one hundred feed submissions in the list. To return metadata about a greater number
 * of canceled feed submissions, you can call GetFeedSubmissionList. If feeds have already begun
 * processing, they cannot be canceled.
 *
 * Calls to CancelFeedSubmissions are limited to one request per minute, included within the
 * overall limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle feed_submission_id_list doc="Array of feed submission IDs"
 * @guzzle feed_type_list doc="Array of FeedType values"
 * @guzzle submitted_from_date doc="Earliest date to look for"
 * @guzzle submitted_to_date doc="Latest date to look for"
 */
class CancelFeedSubmissions extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'CancelFeedSubmissions';

    /**
     * Set feed submission ID list
     *
     * @param array $feedSubmissionIdList
     *
     * @return CancelFeedSubmissions
     */
    public function setFeedSubmissionIdList(array $feedSubmissionIdList)
    {
        return $this->set('feed_submission_id_list', array(
            'Id'    => $feedSubmissionIdList
        ));
    }

    /**
     * Set feed types to filter by
     *
     * @param array $feedTypeList
     *
     * @return CancelFeedSubmissions
     */
    public function setFeedTypeList(array $feedTypeList)
    {
        return $this->set('feed_type_list', array(
            'Type'  => $feedTypeList
        ));
    }

    /**
     * Set submitted fromdate
     *
     * @param \DateTime $submittedFromDate
     *
     * @return CancelFeedSubmissions
     */
    public function setSubmittedFromDate(\DateTime $submittedFromDate)
    {
        return $this->set('submitted_from_date', $submittedFromDate);
    }

    /**
     * Set submitted to date
     *
     * @param \DateTime $submittedToDate
     *
     * @return CancelFeedSubmissions
     */
    public function setSubmittedToDate(\DateTime $submittedToDate)
    {
        return $this->set('submitted_to_date', $submittedToDate);
    }
}