<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Request a report
 *
 * Documentation:
 * The RequestReport operation requests the generation of a report, which creates a report
 * request. Reports are retained for 90 days.
 *
 * Calls to RequestReport are limited to 30 requests per hour, included within the overall
 * limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle report_type doc="ReportType to request" required="true"
 * @guzzle start_date doc="Report start date"
 * @guzzle end_date doc="Report end date"
 */
class RequestReport extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'RequestReport';

    /**
     * Set report type
     *
     * @param string $reportType
     *
     * @return RequestReport
     */
    public function setReportType($reportType)
    {
        return $this->set('report_type', $reportType);
    }

    /**
     * Set report start date
     *
     * @param \DateTime $startDate
     *
     * @return RequestReport
     */
    public function setStartDate(\DateTime $startDate)
    {
        return $this->set('start_date', $startDate);
    }

    /**
     * Set report end date
     *
     * @param \DateTime $endDate
     *
     * @return RequestReport
     */
    public function setEndDate(\DateTime $endDate)
    {
        return $this->set('end_date', $endDate);
    }
}