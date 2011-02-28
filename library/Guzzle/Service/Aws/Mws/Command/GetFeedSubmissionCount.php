<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get total number of feed submissions
 *
 * Documentation:
 * The GetFeedsubmissionCount operation returns a count of the total number of feed submissions
 * within the previous 90 days.
 *
 * Calls to GetFeedSubmissionCount are limited to one request per minute, included within the
 * overall limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle feed_type_list doc="Array of FeedType values to filter by"
 * @guzzle feed_processing_status_list doc="Array of FeedProcessingStatus values to filter by"
 * @guzzle submitted_from_date doc="Beginning date"
 * @guzzle submitted_to_date doc="Ending date"
 */
class GetFeedSubmissionCount extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetFeedSubmissionCount';

    /**
     * Set feed type list
     *
     * @param array $feedTypeList
     *
     * @return GetFeedSubmissionCount
     */
    public function setFeedTypeList(array $feedTypeList)
    {
        return $this->set('feed_type_list', array(
            'Type' => $feedTypeList
        ));
    }

    /**
     * Set feed processing status list
     *
     * @param array $feedProcessingStatusList
     *
     * @return GetFeedSubmissionCount
     */
    public function setFeedProcessingStatusList(array $feedProcessingStatusList)
    {
        return $this->set('feed_processing_status_list', array(
            'Status' => $feedProcessingStatusList
        ));
    }

    /**
     * Set submitted from date
     *
     * @param \DateTime $submittedFromDate
     *
     * @return GetFeedSubmissionCount
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
     * @return GetFeedSubmissionCount
     */
    public function setSubmittedToDate(\DateTime $submittedToDate)
    {
        return $this->set('submitted_to_date', $submittedToDate);
    }
}