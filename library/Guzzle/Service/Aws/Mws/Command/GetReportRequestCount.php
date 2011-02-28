<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get the total number of report requests matching the given parameters
 *
 * Documentation:
 * The GetReportRequestCount returns a count of report requests.
 *
 * Calls to GetReportRequestCount are limited to one request per minute, included within the
 * overall limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle report_type_list doc="Array of ReportType values to filter by"
 * @guzzle report_processing_status_list doc="Array of ReportProcessingStatus values to filter by"
 * @guzzle requested_from_date doc="Begin date"
 * @guzzle requested_to_date doc="End date"
 */
class GetReportRequestCount extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetReportRequestCount';

    /**
     * Set report type list
     *
     * @param array $reportTypeList
     *
     * @return GetReportRequestCount
     */
    public function setReportTypeList(array $reportTypeList)
    {
        return $this->set('report_type_list', array(
            'Type' => $reportTypeList
        ));
    }

    /**
     * Set processing status list
     *
     * @param array $processingStatusList
     *
     * @return GetReportRequestCount
     */
    public function setProcessingStatusList(array $processingStatusList)
    {
        return $this->set('processing_status_list', array(
            'Status' => $processingStatusList
        ));
    }

    /**
     * Set requested from date
     *
     * @param \DateTime $requestedFromDate
     *
     * @return GetReportRequestCount
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
     * @return GetReportRequestCount
     */
    public function setRequestedToDate(\DateTime $requestedToDate)
    {
        return $this->set('requested_to_date', $requestedToDate);
    }
}