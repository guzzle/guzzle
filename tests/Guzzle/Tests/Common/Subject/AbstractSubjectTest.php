<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Tests\Common;

use \Guzzle\Common\Subject\SubjectMediator;

/**
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AbstractSubjectTest extends \Guzzle\Tests\GuzzleTestCase
{
    /**
     * @covers \Guzzle\Common\Subject\AbstractSubject::getSubjectMediator
     */
    public function testGetSubjectMediator()
    {
        $subject = new Mock\MockSubject();
        $mediator = $subject->getSubjectMediator();
        $this->assertInstanceOf('Guzzle\Common\Subject\SubjectMediator', $mediator);
        $this->assertEquals($mediator, $subject->getSubjectMediator());
    }
}