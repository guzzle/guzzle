<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Unfuddle\Command\Tickets;

use Guzzle\Service\Unfuddle\Command\AbstractUnfuddleBodyCommand;

/**
 * Abstract class to create or modify an Unfuddle ticket
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractTicketBodyCommand extends AbstractUnfuddleBodyCommand
{
    /**
     * {@inheritdoc}
     */
    protected $containingElement = 'ticket';
    
    /**
     * Set the ticket assignee
     *
     * @param integer $assigneeId Assignee to set
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setAssigneeId($assigneeId)
    {
        return $this->setXmlValue('assignee-id', $assigneeId);
    }

    /**
     * Set the component id
     *
     * @param integer $componentId Component
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setComponentId($componentId)
    {
        return $this->setXmlValue('component-id', $componentId);
    }

    /**
     * Set the ticket description
     *
     * @param string $description Description to set
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setDescription($description)
    {
        return $this->setXmlValue('description', $description);
    }

    /**
     * Set the due on date
     *
     * @param string $dueOn Ticket due date
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setDueOn($dueOn)
    {
        return $this->setXmlValue('due-on', $dueOn);
    }

    /**
     * Set the current hour estimate
     *
     * @param float $estimate Current time estimate
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setHoursEstimateCurrent($estimate)
    {
        return $this->setXmlValue('hours-estimate-current', $estimate);
    }

    /**
     * Set the initial hour estimate
     *
     * @param float $estimate Initial time estimate
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setHoursEstimateInitial($estimate)
    {
        return $this->setXmlValue('hours-estimate-initial', $estimate);
    }

    /**
     * Set the milestone ID
     *
     * @param integer $milestonId Milestone ID
     *
     * @return AbstractTicketBodyCommand
     */
    public function setMilestoneId($milestoneId)
    {
        return $this->setXmlValue('milestone-id', $milestoneId);
    }

    /**
     * Set the ticket priority
     *
     * @param integer $priority One of 1, 2, 3, 4, 5
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setPriority($priority)
    {
        return $this->setXmlValue('priority', $priority);
    }
    
    /**
     * Set the ticket resolution
     *
     * @param string $resolution One of fixed, works_for_me, postponed,
     *      duplicate, will_not_fix, invalid
     *
     * @return AbstractTicketBodyCommand
     */
    public function setResolution($resolution)
    {
        return $this->setXmlValue('resolution', $resolution);
    }

    /**
     * Set the resolution description
     *
     * @param string $resolutionDescription
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setResolutionDescription($resolutionDescription)
    {
        return $this->setXmlValue('resolution-description', $resolutionDescription);
    }

    /**
     * Set the severity id
     *
     * @param integer $severityId
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setSeverityId($severityId)
    {
        return $this->setXmlValue('severity-id', $severityId);
    }

    /**
     * Set the ticket status
     *
     * @param string $status One of new, unaccepted, reassigned, reopened,
     *      accepted, resolved, closed
     *
     * @return AbstractTicketBodyCommand
     */
    public function setStatus($status)
    {
        return $this->setXmlValue('status', $status);
    }

    /**
     * Set the ticket summary
     *
     * @param string $summary
     * 
     * @return AbstractTicketBodyCommand
     */
    public function setSummary($summary)
    {
        return $this->setXmlValue('summary', $summary);
    }
}