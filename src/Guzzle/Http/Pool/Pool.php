<?php

namespace Guzzle\Http\Pool;

use Guzzle\Common\Event\AbstractSubject;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestException;

/**
 * Execute a pool of {@see RequestInterface} objects in parallel.
 *
 * Signals emitted:
 *
 *  event           context             description
 *  -----           -------             -----------
 *  add_request     RequestInterface    A request was added to the pool
 *  remove_request  RequestInterface    A request was removed from the pool
 *  reset           null                The pool was reset
 *  before_send     array               The pool is about to be sent
 *  complete        array               The pool finished sending the requests
 *  polling_request RequestInterface    A request is still polling
 *  polling         null                Some requests are still polling
 *  exception       RequestException    A request exception occurred
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
    protected $requests = array();

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
    public function add(RequestInterface $request)
    {
        if ($this->state != self::STATE_COMPLETE) {
            $this->requests[] = $request;
        }

        if ($this->state == self::STATE_SENDING) {
            // Attach a request while the pool is being sent.  This is currently
            // used to implement exponential backoff
            curl_multi_add_handle($this->multiHandle, $request->getCurlHandle()->getHandle());
        }

        $this->getEventManager()->notify(self::ADD_REQUEST, $request);

        // Associate the pool with the request
        $request->getParams()->set('pool', $this);

        return $request;
    }

    /**
     * Get an array of attached {@see RequestInterface}s.
     *
     * @return array Returns an array of attached requests.
     */
    public function all()
    {
        return $this->requests;
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
    public function remove(RequestInterface $request)
    {
        if ($this->state == self::STATE_SENDING && $this->multiHandle) {
            curl_multi_remove_handle($this->multiHandle, $request->getCurlHandle()->getHandle());
        }

        $this->requests = array_values(array_filter($this->requests, function($req) use ($request) {
            return $req !== $request;
        }));

        $this->getEventManager()->notify(self::REMOVE_REQUEST, $request);

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
        foreach ($this->requests as $request) {
            $this->remove($request);
        }

        $this->state = self::STATE_IDLE;

        // Close and reopen the curl_multi resource
        if (is_resource($this->multiHandle)) {
            curl_multi_close($this->multiHandle);
        }

        $this->multiHandle = curl_multi_init();

        // Notify any observers of the reset event
        $this->getEventManager()->notify(self::RESET);
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

        $this->getEventManager()->notify(self::BEFORE_SEND, $this->requests);
        $this->state = self::STATE_SENDING;

        foreach ($this->requests as $request) {
            $request->getEventManager()->notify('request.before_send');
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
            while (($execrun = curl_multi_exec($this->multiHandle, $isRunning)) == CURLM_CALL_MULTI_PERFORM);

            // @codeCoverageIgnoreStart
            if ($execrun != CURLM_OK) {
                break; // If an error occurred.
            }
            // @codeCoverageIgnoreEnd

            // Get information about the handle
            while ($done = curl_multi_info_read($this->multiHandle)) {
                foreach ($this->requests as $request) {
                    if ($request->getCurlHandle()->isMyHandle($done['handle'])) {
                        try {
                            $request->setState(RequestInterface::STATE_COMPLETE);
                        } catch (RequestException $e) {
                            $this->getEventManager()->notify('exception', $e);
                            $exceptions[] = $e;
                        }
                        curl_multi_remove_handle($this->multiHandle, $done['handle']);
                    }
                }
            }

            $finished = true;
            foreach ($this->requests as $request) {
                if ($request->getState() != RequestInterface::STATE_COMPLETE) {
                    // Notify each request's event manager that it is polling
                    $request->getEventManager()->notify(self::POLLING_REQUEST, $this);
                    $finished = false;
                }
            }

            // Notify any listeners that the pool is polling.  Good place for
            // status updates
            $this->getEventManager()->notify(self::POLLING);

            // @codeCoverageIgnoreStart
            if ($isRunning) {
                while (($selectResult = curl_multi_select($this->multiHandle, 5)) === 0);
                if ($selectResult == -1) {
                    break;
                }
            } else if (!$finished) {
                // Requests are not actually pending a cURL select call, so
                // we need to delay in order to prevent eating too much CPU
                usleep(30000);
            }
            // @codeCoverageIgnoreEnd

        } while ($isRunning || !$finished);

        $this->state = self::STATE_COMPLETE;
        $this->getEventManager()->notify(self::COMPLETE, $this->requests);

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

        return $this->requests;
    }

    /**
     * Get the number of requests in the pool
     *
     * @return int
     */
    public function count()
    {
        return count($this->requests);
    }
}