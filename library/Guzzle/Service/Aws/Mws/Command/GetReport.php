<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Get a report
 *
 * Documentation:
 * The GetReport operation returns the contents of a report and the Content-MD5
 * header for the returned body. Reports are retained for 90 days from the time they have been
 * generated.
 *
 * You should compute the MD5 hash of the HTTP body and compare that with the returned
 * Content-MD5 header value. If they do not match, which means the body was corrupted during
 * transmission, you should discard the result and automatically retry the call for up to three
 * more times.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle report_id doc="Report ID" required="true"
 */
class GetReport extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'GetReport';

    /**
     * Set report ID to retrieve
     *
     * @param int $reportId
     *
     * @return GetReport
     */
    public function setReportId($reportId)
    {
        return $this->set('report_id', $reportId);
    }
}