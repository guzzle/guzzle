<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get list of feed submissions
 *
 * Documentation:
 * The GetFeedSubmissionList operation returns the total list of feed submissions within the
 * previous 90 days that match the query parameters.
 *
 * Calls to GetFeedSubmissionList are limited to 1 request per minute, included within the
 * overall limit of 1,000 calls per seller account per hour.
 *
 * The maximum number of results that will be returned in one call is one hundred. If there are
 * additional results to return, HasNext will be returned in the response with a true value. To
 * retrieve all the results, you can use the value of the NextToken parameter to call
 * GetFeedSubmissionListByNextToken until HasNext is false.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle feed_submission_id_list doc="Array of feed submission ids"
 * @guzzle max_count doc="maximum number to return in list"
 * @guzzle feed_type_list doc="Array of FeedType values to filter by"
 * @guzzle feed_processing_status_list doc="Array of FeedProcessingStatus values to filter by"
 * @guzzle submitted_from_date doc="Earliest date to return"
 * @guzzle submitted_to_date doc="Latest date to return"
 */
class GetFeedSubmissionList extends AbstractMwsCommand implements IterableInterface
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetFeedSubmissionList';

    /**
     * Set feed submission id list
     *
     * @param array $feedSubmissionIdList
     *
     * @return GetFeedSubmissionList
     */
    public function setFeedSubmissionIdList(array $feedSubmissionIdList)
    {
        return $this->set('feed_submission_id_list', array(
            'Id' => $feedSubmissionIdList
        ));
    }

    /**
     * Set max count
     *
     * @param int $maxCount
     *
     * @return GetFeedSubmissionList
     */
    public function setMaxCount($maxCount)
    {
        return $this->set('max_count', $maxCount);
    }

    /**
     * Set feed type list
     *
     * @param array $feedTypeList
     *
     * @return GetFeedSubmissionList
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
     * @return GetFeedSubmissionList
     */
    public function setFeedProcessingStatusList(array $feedProcessingStatusList)
    {
        return $this->set('feed_processing_status_list', array(
            'Status'  => $feedProcessingStatusList
        ));
    }

    /**
     * Set submitted from date
     *
     * @param \DateTime $submittedFromDate
     *
     * @return GetFeedSubmissionList
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
     * @return GetFeedSubmissionList
     */
    public function setSubmittedToDate(\DateTime $submittedToDate)
    {
        return $this->set('submitted_to_date', $submittedToDate);
    }
}