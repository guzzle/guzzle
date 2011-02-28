<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Subject;

/**
 * Guzzle Observer class
 *
 * @author Michael Dowling <michael@guzzle-project.org>
 */
interface Observer
{
    /**
     * Receive notifications from a SubjectMediator
     *
     * @param SubjectMediator $subject Subject mediator sending the update
     */
    public function update(SubjectMediator $subject);
}