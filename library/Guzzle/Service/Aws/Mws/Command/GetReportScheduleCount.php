<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get the number of scheduled reports
 *
 * Documentation:
 * The GetReportScheduleCount operation returns a count of report schedules. Currently, only
 * order reports can be scheduled.
 *
 * Calls to GetReportScheduleCount are limited to one request per minute, included within the
 * overall limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle report_type_list doc="Array of ReportType values to filter by"
 */
class GetReportScheduleCount extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetReportScheduleCount';

    /**
     * Set report type list
     *
     * @param array $reportTypeList
     *
     * @return GetReportScheduleCount
     */
    public function setReportTypeList(array $reportTypeList)
    {
        return $this->set('report_type_list', array(
            'Type'  => $reportTypeList
        ));
    }
}