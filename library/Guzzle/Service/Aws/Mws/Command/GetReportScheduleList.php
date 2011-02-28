<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get list of scheduled reports
 *
 * Documentation:
 * The ManageReportSchedule operation creates, updates, or deletes a report schedule for a
 * particular report type. Currently, only order reports can be scheduled.
 *
 * Calls to ManageReportSchedule are limited to one request per minute, included within the
 * overall limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle report_type_list doc="Array of ReportType values to filter by"
 */
class GetReportScheduleList extends AbstractMwsCommand implements IterableInterface
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetReportScheduleList';

    /**
     * Set report type list
     *
     * @param array $reportTypeList
     *
     * @return GetReportScheduleList
     */
    public function setReportTypeList(array $reportTypeList)
    {
        return $this->set('report_type_list', array(
            'Type'  => $reportTypeList
        ));
    }
}