<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Plugin;

use Guzzle\Common;
use Guzzle\Common\Event\Subject;
use Guzzle\Common\Event\Observer;
use Guzzle\Http\Message\RequestInterface;

/**
 * Plugin class that adds behavior to a {@see RequestInterface}
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractPlugin implements Observer
{
    /**
     * @var int Priority to attach to this plugin.  Override in subclasses.
     */
    protected $priority = 0;

    /**
     * Check if the plugin is attached to a request
     *
     * @param RequestInterface $request Request to check
     *
     * @return bool
     */
    public function isAttached(RequestInterface $request)
    {
        return $request->getEventManager()->hasObserver($this);
    }

    /**
     * Attach the plugin to a request object
     *
     * @param RequestInterface $request Request to apply to the plugin to
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function attach(RequestInterface $request)
    {
        if ($this->isAttached($request)) {
            return false;
        }

        // Make sure that the subclass can apply to this request
        $application = $this->handleAttach($request);
        // @codeCoverageIgnoreStart
        if (!is_null($application) && $application === false) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        $request->getEventManager()->attach($this, $this->priority);

        return true;
    }

    /**
     * Detach the plugin from the request object
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function detach(RequestInterface $request)
    {
        if (!$this->isAttached($request)) {
            return false;
        }

        $request->getEventManager()->detach($this);

        return true;
    }

    /**
     * {@inheritdoc}
     * @codeCoverageIgnoreStart
     */
    public function update(Subject $subject, $event, $context = null)
    {
    }

    /**
     * Hook to run when the plugin is attached to a request
     *
     * @param RequestInterface $request Request to attach to
     *
     * @return null|bool Returns TRUE or FALSE if it can attach or NULL if indifferent
     * @codeCoverageIgnoreStart
     */
    protected function handleAttach(RequestInterface $request)
    {
    }
}