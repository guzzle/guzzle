<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Manage report schedule
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
 * @guzzle report_type doc="ReportType to schedule" required="true"
 * @guzzle schedule doc="Schedule value for how often to generate report" required="true"
 * @guzzle scheduled_date doc="Date when the next report is to run"
 */
class ManageReportSchedule extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'ManageReportSchedule';
    
    /**
     * Set report type
     *
     * @param string $reportType
     *
     * @return ManageReportSchedule
     */
    public function setReportType($reportType)
    {
        return $this->set('report_type', $reportType);
    }

    /**
     * Set report schedule
     *
     * @param string $schedule
     *
     * @return ManageReportSchedule
     */
    public function setSchedule($schedule)
    {
        return $this->set('schedule', $schedule);
    }

    /**
     * Set scheduled date
     *
     * @param \DateTime $scheduledDate
     *
     * @return ManageReportSchedule
     */
    public function setScheduledDate(\DateTime $scheduledDate)
    {
        return $this->set('scheduled_date', $scheduledDate);
    }
}