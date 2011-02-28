<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get a list of reports matching the given parameters
 *
 * Documentation:
 * The GetReportList operation returns a list of reports within the previous 90 days that match
 * the query parameters. The maximum number of results that will be returned in one call is one
 * hundred. If there are additional results to return, HasNext will be returned in the response
 * with a true value. To retrieve all the results, you can use the value of the NextToken
 * parameter to call GetReportListByNextToken until HasNext is false.
 *
 * Calls to GetReportList are limited to one request per minute, included within the overall
 * limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle max_count doc="Maximum number of reports to return"
 * @guzzle report_type_list doc="An array of ReportTypes by which to filter reports"
 * @guzzle acknowledged doc="set to true to list acknowledged reports"
 * @guzzle available_from_date doc="Earliest report date"
 * @guzzle available_to_date doc="Most recent report date"
 * @guzzle report_request_id_list doc="An array of report request IDs"
 */
class GetReportList extends AbstractMwsCommand implements IterableInterface
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetReportList';

    /**
     * Set the maximum number of results to return
     *
     * @param int $maxCount
     *
     * @return GetReportList
     */
    public function setMaxCount($maxCount)
    {
        return $this->set('max_count', $maxCount);
    }

    /**
     * Set the report type list by which to filter
     *
     * @param array $reportTypes
     *
     * @return GetReport
     */
    public function setReportTypeList(array $reportTypes)
    {
        return $this->set('report_type_list', array(
            'Type' => $reportTypes
        ));
    }

    /**
     * Set whether or not to return acknowledged reports
     *
     * @param bool $acknowledged
     *
     * @return GetReport
     */
    public function setAcknowledged($acknowledged)
    {
        return $this->set('acknowledged', $acknowledged);
    }

    /**
     * Set earliest date to return
     *
     * @param \DateTime $availableFromDate
     *
     * @return GetReport
     */
    public function setAvailableFromDate(\DateTime $availableFromDate)
    {
        return $this->set('available_from_date', $availableFromDate);
    }

    /**
     * Set latest date to return
     *
     * @param \DateTime $availableToDate
     *
     * @return GetReport
     */
    public function setAvailableToDate(\DateTime $availableToDate)
    {
        return $this->set('available_to_date', $availableToDate);
    }

    /**
     * Set report request Id list
     *
     * @param array $reportRequestIdList
     *
     * @return GetReport
     */
    public function setReportRequestIdList(array $reportRequestIdList)
    {
        return $this->set('report_request_id_list', array(
            'Id' => $reportRequestIdList
        ));
    }
}