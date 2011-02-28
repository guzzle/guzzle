<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get report request list matching the given parameters
 *
 * Documentation:
 * The GetReportRequestList operation returns a list of report requests that match the query
 * parameters.
 *
 * Calls to GetReportRequestList are limited to one request per minute, included within the
 * overall limit of 1,000 calls per seller account per hour.
 *
 * The maximum number of results that will be returned in one call is one hundred. If there
 * are additional results to return, HasNext will be returned in the response with a true
 * value. To retrieve all the results, you can use the value of the NextToken parameter to
 * call GetReportRequestListByNextToken until HasNext is false.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle report_request_id_list doc="Array of report request ids"
 * @guzzle report_type_list doc="Array of ReportType values"
 * @guzzle report_processing_status_list doc="Array of ReportProcessingStatus values"
 * @guzzle max_count doc="Max result count"
 * @guzzle requested_from_date doc="Begin date"
 * @guzzle requested_to_date doc="End date"
 */
class GetReportRequestList extends AbstractMwsCommand implements IterableInterface
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetReportRequestList';

    /**
     * Set report request ID list
     *
     * @param array $reportRequestIdList
     *
     * @return GetReportRequestList
     */
    public function setReportRequestIdList(array $reportRequestIdList)
    {
        return $this->set('report_request_id_list', array(
            'Id' => $reportRequestIdList
        ));
    }

    /**
     * Set report type list
     *
     * @param array $reportTypeList
     *
     * @return GetReportRequestList
     */
    public function setReportTypeList(array $reportTypeList)
    {
        return $this->set('report_type_list', array(
            'Type' => $reportTypeList
        ));
    }

    /**
     * Set report processing status list
     *
     * @param array $reportProcessingStatusList
     *
     * @return GetReportRequestList
     */
    public function setReportProcessingStatusList(array $reportProcessingStatusList)
    {
        return $this->set('report_processing_status_list', array(
            'Status' => $reportProcessingStatusList
        ));
    }

    /**
     * Set max count
     *
     * @param int $maxCount
     *
     * @return GetReportRequestList
     */
    public function setMaxCount($maxCount)
    {
        return $this->set('max_count', $maxCount);
    }

    /**
     * Set requested from date
     *
     * @param \DateTime $requestedFromDate
     *
     * @return GetReportRequestList
     */
    public function setRequestedFromDate(\DateTime $requestedFromDate)
    {
        return $this->set('requested_from_date', $requestedFromDate);
    }

    /**
     * Set requested to date
     *
     * @param \DateTime $requestedToDate
     *
     * @return GetReportRequestList
     */
    public function setRequestedToDate(\DateTime $requestedToDate)
    {
        return $this->set('requested_to_date', $requestedToDate);
    }
}
