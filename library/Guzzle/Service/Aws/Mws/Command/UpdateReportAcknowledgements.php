<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

/**
 * Update report acknowledgements
 *
 * Documentation:
 * The UpdateReportAcknowledgements operation is an optional function that you should use only
 * if you want Amazon to remember the Acknowledged status of your reports.
 * UpdateReportAcknowledgements updates the acknowledged status of one or more reports. To keep
 * track of which reports you have already received, it is a good practice to acknowledge
 * reports after you have received and stored them successfully. Then, when you call
 * GetReportList you can specify to receive only reports that have not yet been acknowledged.
 *
 * You can also use this function to retrieve reports that have been lost, possibly because of
 * a hard disk failure, by setting Acknowledged to false and then calling GetReportList, which
 * returns a list of reports within the previous 90 days that match the query parameters.
 *
 * Calls to UpdateReportAcknowledgements are limited to one request per minute, included within
 * the overall limit of 1,000 calls per seller account per hour.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 *
 * @guzzle report_id_list doc="Array of ids to update" required="true"
 * @guzzle acknowledged doc="Acknowledged flag"
 */
class UpdateReportAcknowledgements extends AbstractMwsCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'UpdateReportAcknowledgements';

    /**
     * Set report ID list
     *
     * @param array $reportIdList
     *
     * @return UpdateReportAcknowledgements
     */
    public function setReportIdList(array $reportIdList)
    {
        return $this->set('report_id_list', array(
            'Id'    => $reportIdList
        ));
    }

    /**
     * Set acknowledgements
     *
     * @param bool $acknowledged
     *
     * @return UpdateReportAcknowledgements
     */
    public function setAcknowledged($acknowledged)
    {
        return $this->set('acknowledged', $acknowledged);
    }
}