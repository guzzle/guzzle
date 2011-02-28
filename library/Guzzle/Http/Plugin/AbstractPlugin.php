<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Http\Plugin;

use Guzzle\Common;
use Guzzle\Common\Filter\FilterInterface;
use Guzzle\Common\Subject\SubjectMediator;
use Guzzle\Common\Subject\Observer;
use Guzzle\Http\Message\RequestInterface;

/**
 * Plugin class that adds behavior to a {@see RequestInterface}
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractPlugin implements Observer, FilterInterface
{
    /**
     * Check if the plugin is attached to a request
     *
     * @param RequestInterface $request Request to check
     *
     * @return bool
     */
    public function isAttached(RequestInterface $request)
    {
        return $request->getSubjectMediator()->hasObserver($this) && $request->getPrepareChain()->hasFilter($this) && $request->getProcessChain()->hasFilter($this);
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

        $request->getSubjectMediator()->attach($this);
        $request->getPrepareChain()->addFilter($this);
        $request->getProcessChain()->addFilter($this);

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

        $request->getSubjectMediator()->detach($this);
        $request->getPrepareChain()->removeFilter($this);
        $request->getProcessChain()->removeFilter($this);

        return true;
    }

    /**
     * Observer update method
     *
     * @param SubjectMediator $subject Subject of the notification
     * @codeCoverageIgnoreStart
     */
    public function update(SubjectMediator $subject) {}

    /**
     * Intercepting filter process method
     *
     * @param RequestInterface $context
     * @codeCoverageIgnoreStart
     */
    public function process($context) {}

    /**
     * Hook to run when the plugin is attached to a request
     *
     * @param RequestInterface $request Request to attach to
     *
     * @return null|bool Returns TRUE or FALSE if it can attach or NULL if indifferent
     * @codeCoverageIgnoreStart
     */
    protected function handleAttach(RequestInterface $request) {}
}