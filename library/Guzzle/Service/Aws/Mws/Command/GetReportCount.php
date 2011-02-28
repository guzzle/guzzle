<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get the number of reports matching the given parameters
 *
 * Documentation:
 * The GetReportCount operation returns a count of reports within the previous 90 days that
 * are available for the seller to download.
 *
 * Calls to GetReportCount are limited to one request per minute, included within the overall
 * limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle report_type_list doc="An array of ReportTypes by which to filter"
 * @guzzle acknowledged doc="Whether or not to return acknowledged reports"
 * @guzzle available_from_date doc="Report begin date"
 * @guzzle available_to_date doc="Report end date"
 */
class GetReportCount extends AbstractMwsCommand
{
    protected $action = 'GetReportCount';

    /**
     * Set report type list
     * 
     * @param array $reportTypeList 
     * 
     * @return GetReportCount
     */
    public function setReportTypeList(array $reportTypeList)
    {
        return $this->set('report_type_list', array(
            'Type' => $reportTypeList
        ));
    }

    /**
     * Set acknowledged filter
     *
     * @param bool $acknowledged
     *
     * @return GetReportList
     */
    public function setAcknowledged($acknowledged)
    {
        return $this->set('acknowledged', $acknowledged);
    }

    /**
     * Set available from date
     *
     * @param \DateTime $availableFromDate
     *
     * @return GetReportList
     */
    public function setAvailableFromDate(\DateTime $availableFromDate)
    {
        return $this->set('available_from_date', $availableFromDate);
    }

    /**
     * Set available todate
     *
     * @param \DateTime $availableToDate
     *
     * @return GetReportList
     */
    public function setAvailableToDate(\DateTime $availableToDate)
    {
        return $this->set('available_to_date', $availableToDate);
    }
}