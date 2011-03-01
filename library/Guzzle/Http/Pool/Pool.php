<?php

namespace Guzzle\Http\Pool;

use Guzzle\Common\Subject\AbstractSubject;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestException;

/**
 * Execute a pool of {@see RequestInterface} objects in parallel.
 *
 * @author  michael@guzzlephp.org
 */
class Pool extends AbstractSubject implements PoolInterface
{
    /**
     * @var resource cURL multi handle.
     */
    protected $multiHandle;

    /**
     * @var array Attached {@see RequestInterface} objects.
     */
    protected $attached = array();

    /**
     * @var string The current state of the pool
     */
    protected $state = self::STATE_IDLE;

    /**
     * Construct the request pool.
     */
    function __construct()
    {
        $this->multiHandle = curl_multi_init();
    }

    /**
     * Destroy the request pool.
     */
    function __destruct()
    {
        if (is_resource($this->multiHandle)) {
            curl_multi_close($this->multiHandle);
        }
    }

    /**
     * Add a request to the pool.
     *
     * @param RequestInterface $request Returns the Request that was added
     */
    public function addRequest(RequestInterface $request)
    {
        if ($this->state != self::STATE_COMPLETE) {
            $this->attached[] = $request;
        }

        if ($this->state == self::STATE_SENDING) {
            // Attach a request while the pool is being sent.  This is currently
            // used to implement exponential backoff
            curl_multi_add_handle($this->multiHandle, $request->getCurlHandle()->getHandle());
        }

        $this->getSubjectMediator()->notify(self::ADD_REQUEST, $request, true);

        // Associate the pool with the request
        $request->getParams()->set('pool', $this);

        return $request;
    }

    /**
     * Get an array of attached {@see RequestInterface}s.
     *
     * @return array Returns an array of attached requests.
     */
    public function getRequests()
    {
        return $this->attached;
    }

    /**
     * Get the current state of the Pool
     *
     * @return string
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * Remove a request from the pool.
     *
     * @param RequestInterface $request Request to detach.
     *
     * @return RequestInterface Returns the Request object that was removed
     */
    public function removeRequest(RequestInterface $request)
    {
        if ($this->state == self::STATE_SENDING && $this->multiHandle) {
            curl_multi_remove_handle($this->multiHandle, $request->getCurlHandle()->getHandle());
        }

        $this->attached = array_values(array_filter($this->attached, function($req) use ($request) {
            return $req !== $request;
        }));

        $this->getSubjectMediator()->notify(self::REMOVE_REQUEST, $request, true);

        // Remove the pool's request association
        $request->getParams()->remove('pool');

        return $request;
    }

    /**
     * Reset the state of the Pool and remove any attached RequestInterface objects
     */
    public function reset()
    {
        // Remove each request
        foreach ($this->attached as $request) {
            $this->removeRequest($request);
        }

        $this->state = self::STATE_IDLE;

        // Close and reopen the curl_multi resource
        if (is_resource($this->multiHandle)) {
            curl_multi_close($this->multiHandle);
        }

        $this->multiHandle = curl_multi_init();

        // Notify any observers of the reset event
        $this->getSubjectMediator()->notify(self::RESET);
    }

    /**
     * Send a pool of {@see RequestInterface} requests.
     *
     * Calling this method more than once will return FALSE.
     *
     * @return array|bool Returns an array of attached Request objects on
     *      success FALSE on failure.
     *
     * @throws PoolRequestException if any requests threw exceptions during the
     *      transfer.
     */
    public function send()
    {
        if ($this->state == self::STATE_COMPLETE) {
            return false;
        }

        $this->getSubjectMediator()->notify(self::BEFORE_SEND, $this->attached, true);
        $this->state = self::STATE_SENDING;

        foreach ($this->attached as $request) {
            // Do not send if a manual response is being used.
            if (!$request->getResponse() && !$request->getParams()->get('queued_response')) {
                curl_multi_add_handle($this->multiHandle, $request->getCurlHandle()->getHandle());
            } else if ($request->getParams()->get('queued_response')) {
                $request->setState(RequestInterface::STATE_COMPLETE);
            }
        }

        $isRunning = false;
        $exceptions = array();

        do {
            // Exec until there's no more data in this iteration.
            while(($execrun = curl_multi_exec($this->multiHandle, $isRunning)) == CURLM_CALL_MULTI_PERFORM);

            // @codeCoverageIgnoreStart
            if ($execrun != CURLM_OK) {
                break; // If an error occurred.
            }
            // @codeCoverageIgnoreEnd

            // Get information about the handle
            while ($done = curl_multi_info_read($this->multiHandle)) {
                foreach ($this->attached as $request) {
                    if ($request->getCurlHandle()->isMyHandle($done['handle'])) {
                        try {
                            $request->setState(RequestInterface::STATE_COMPLETE);
                        } catch (RequestException $e) {
                            $this->getSubjectMediator()->notify('exception', $e);
                            $exceptions[] = $e;
                        }
                        curl_multi_remove_handle($this->multiHandle, $done['handle']);
                    }
                }
            }

            $finished = true;
            foreach ($this->attached as $request) {
                if ($request->getState() != RequestInterface::STATE_COMPLETE) {
                    $finished = false;
                    break;
                }
            }

            $this->getSubjectMediator()->notify(self::POLLING, null);

            // @codeCoverageIgnoreStart
            if ($isRunning) {
                while (($selectResult = curl_multi_select($this->multiHandle, 5)) === 0);
                if ($selectResult == -1) {
                    break;
                }
            }
            // @codeCoverageIgnoreEnd

        } while ($isRunning || !$finished);

        $this->state = self::STATE_COMPLETE;
        $this->getSubjectMediator()->notify(self::COMPLETE, $this->attached, true);

        // Throw any Request exceptions encountered during the transfer
        if (count($exceptions)) {
            $poolException = new PoolRequestException(
                'RequestExceptions thrown during transfer'
            );
            foreach ($exceptions as $e) {
                $poolException->addException($e);
            }

            throw $poolException;
        }

        return $this->attached;
    }
}