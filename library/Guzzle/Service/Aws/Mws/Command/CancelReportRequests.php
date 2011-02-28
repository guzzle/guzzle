<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Cancel report requests
 *
 * Documentation:
 * The CancelReportRequests operation cancels one or more report requests, returning the count
 * of the canceled report requests and the report request information. You can specify a number
 * to cancel of greater than one hundred, but information will only be returned about the first
 * one hundred report requests in the list. To return metadata about a greater number of
 * canceled report requests, you can call GetReportRequestList. If report requests have already
 * begun processing, they cannot be canceled.
 *
 * Calls to CancelReportRequests are limited to one request per minute, included within the
 * overall limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle report_request_id_list doc="Array of report request IDs"
 * @guzzle report_type_list doc="Array of report types"
 * @guzzle report_processing_status_list doc="Array of report processing statuses"
 * @guzzle requested_from_date doc="Earliest date to match"
 * @guzzle requested_to_date doc="Latest date to match"
 */
class CancelReportRequests extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'CancelReportRequests';

    /**
     * Set report request id list
     *
     * @param array $reportRequestIdList
     *
     * @return CancelReportRequests
     */
    public function setReportRequestIdList(array $reportRequestIdList)
    {
        return $this->set('report_request_id_list', array(
            'Id'    => $reportRequestIdList
        ));
    }

    /**
     * Set report type list
     *
     * @param array $reportTypeList
     *
     * @return CancelReportRequests
     */
    public function setReportTypeList(array $reportTypeList)
    {
        return $this->set('report_type_list', array(
            'Type'  => $reportTypeList
        ));
    }

    /**
     * Set report processing status list
     *
     * @param array $reportProcessingStatusList
     *
     * @return CancelReportRequests
     */
    public function setReportProcessingStatusList(array $reportProcessingStatusList)
    {
        return $this->set('report_processing_status_list', array(
            'Status' => $reportProcessingStatusList
        ));
    }

    /**
     * Set requested from date
     *
     * @param \DateTime $requestedFromDate
     *
     * @return CancelReportRequests
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
     * @return CancelReportRequests
     */
    public function setRequestedToDate(\DateTime $requestedToDate)
    {
        return $this->set('requested_to_date', $requestedToDate);
    }

}