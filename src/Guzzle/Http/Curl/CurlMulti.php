<?php

namespace Guzzle\Http\Curl;

use Guzzle\Common\AbstractHasDispatcher;
use Guzzle\Http\Exception\MultiTransferException;
use Guzzle\Http\Exception\CurlException;
use Guzzle\Http\Message\RequestInterface;

/**
 * Send {@see RequestInterface} objects in parallel using curl_multi
 *
 * This implementation allows callers to send blocking requests that return back to the caller when their requests
 * complete, regardless of whether or not previously sending requests in the curl_multi object have completed.  The
 * implementation relies on managing the recursion scope in which a caller adds a request to the CurlMulti object, and
 * tracking the requests in the current scope until they complete.  Although the CurlMulti object only tracks whether
 * or not requests in the current scope have completed, it still sends all requests added to the object in parallel.
 */
class CurlMulti extends AbstractHasDispatcher implements CurlMultiInterface
{
    /**
     * @var resource cURL multi handle.
     */
    protected $multiHandle;

    /**
     * @var string The current state of the pool
     */
    protected $state = self::STATE_IDLE;

    /**
     * @var array Attached {@see RequestInterface} objects.
     */
    protected $requests;

    /**
     * @var array Cache of all requests currently in any scope
     */
    protected $requestCache;

    /**
     * @var \SplObjectStorage {@see RequestInterface} to {@see CurlHandle} storage
     */
    protected $handles;

    /**
     * @var array Hash mapping curl handle resource IDs to request objects
     */
    protected $resourceHash;

    /**
     * @var array Queued exceptions
     */
    protected $exceptions = array();

    /**
     * @var array Queue of handles to remove once everything completes
     */
    protected $removeHandles;

    /**
     * @var array cURL multi error values and codes
     */
    protected $multiErrors = array(
        CURLM_BAD_HANDLE      => array('CURLM_BAD_HANDLE', 'The passed-in handle is not a valid CURLM handle.'),
        CURLM_BAD_EASY_HANDLE => array('CURLM_BAD_EASY_HANDLE', "An easy handle was not good/valid. It could mean that it isn't an easy handle at all, or possibly that the handle already is in used by this or another multi handle."),
        CURLM_OUT_OF_MEMORY   => array('CURLM_OUT_OF_MEMORY', 'You are doomed.'),
        CURLM_INTERNAL_ERROR  => array('CURLM_INTERNAL_ERROR', 'This can only be returned if libcurl bugs. Please report it to us!')
    );

    /**
     * @var CurlMulti
     */
    private static $instance;

    /**
     * @var int
     */
    private $scope = -1;

    /**
     * {@inheritdoc}
     */
    public static function getAllEvents()
    {
        return array(
            // A request was added
            self::ADD_REQUEST,
            // A request was removed
            self::REMOVE_REQUEST,
            // Requests are about to be sent
            self::BEFORE_SEND,
            // The pool finished sending the requests
            self::COMPLETE,
            // A request is still polling (sent to request's event dispatchers)
            self::POLLING_REQUEST,
            // A request exception occurred
            self::MULTI_EXCEPTION
        );
    }

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        // You can get some weird "Too many open files" errors when sending a large amount of requests in parallel.These
        // two statements autoload classes before a system runs out of file descriptors so that you can get back
        // valuable error messages if you run out.
        class_exists('Guzzle\Http\Message\Response');
        class_exists('Guzzle\Http\Exception\CurlException');

        $this->createMultiHandle();
    }

    /**
     * {@inheritdoc}
     */
    public function __destruct()
    {
        if (is_resource($this->multiHandle)) {
            curl_multi_close($this->multiHandle);
        }
    }

    /**
     * {@inheritdoc}
     *
     * Adds a request to a batch of requests to be sent in parallel.
     *
     * Async requests adds a request to the current scope to be executed in parallel with any currently executing cURL
     * handles. You may only add an async request while other requests are transferring. Attempting to add an async
     * request while no requests are transferring will add the request normally in the next available scope (e.g. 0).
     *
     * @param RequestInterface $request Request to add
     * @param bool             $async   Set to TRUE to add to the current scope
     *
     * @return self
     */
    public function add(RequestInterface $request, $async = false)
    {
        if ($async && $this->state != self::STATE_SENDING) {
            $async = false;
        }

        $this->requestCache = null;
        $scope = $async ? $this->scope : $this->scope + 1;

        if (!isset($this->requests[$scope])) {
            $this->requests[$scope] = array($request);
        } else {
            $this->requests[$scope][] = $request;
        }

        $this->dispatch(self::ADD_REQUEST, array('request' => $request));

        // If requests are currently transferring and this is async, then the
        // request must be prepared now as the send() method is not called.
        if ($async && $this->state == self::STATE_SENDING) {
            $this->beforeSend($request);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function all()
    {
        if (!$this->requestCache) {
            $this->requestCache = empty($this->requests) ? array() : call_user_func_array('array_merge', $this->requests);
        }

        return $this->requestCache;
    }

    /**
     * {@inheritdoc}
     */
    public function getState()
    {
        return $this->state;
    }

    /**
     * {@inheritdoc}
     */
    public function remove(RequestInterface $request)
    {
        $this->removeHandle($request);
        $this->requestCache = null;

        foreach ($this->requests as $scope => $scopedRequests) {
            $pos = array_search($request, $scopedRequests, true);
            if ($pos !== false) {
                unset($this->requests[$scope][$pos]);
                break;
            }
        }

        $this->dispatch(self::REMOVE_REQUEST, array('request' => $request));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function reset($hard = false)
    {
        // Remove each request
        foreach ($this->all() as $request) {
            $this->remove($request);
        }

        $this->requests = array();
        $this->exceptions = array();
        $this->state = self::STATE_IDLE;
        $this->scope = -1;
        $this->requestCache = null;

        // Remove any curl handles that were queued for removal
        if ($this->scope == -1 || $hard) {
            foreach ($this->removeHandles as $handle) {
                curl_multi_remove_handle($this->multiHandle, $handle->getHandle());
                $handle->close();
            }
            $this->removeHandles = array();
        }

        if ($hard) {
            $this->createMultiHandle();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function send()
    {
        $this->scope++;
        $this->state = self::STATE_SENDING;
        $requestsInScope = empty($this->requests[$this->scope]) ? array() : $this->requests[$this->scope];

        // Only prepare and send requests that are in the current recursion scope
        // Only enter the main perform() loop if there are requests in scope
        if ($requestsInScope) {

            // Any exceptions thrown from this event should break the entire flow of sending requests
            $this->dispatch(self::BEFORE_SEND, array(
                'requests' => $this->requests[$this->scope]
            ));

            foreach ($this->requests[$this->scope] as $request) {
                if ($request->getState() != RequestInterface::STATE_TRANSFER) {
                    $this->beforeSend($request);
                }
            }

            try {
                $this->perform();
            } catch (\Exception $e) {
                $this->exceptions[] = array('request' => null, 'exception' => $e);
            }
        }

        $this->scope--;

        // Aggregate exceptions into a MultiTransferException if needed
        $multiException = $this->buildMultiTransferException($requestsInScope);

        // Complete the transfer if this is not a nested scope
        if ($this->scope == -1) {
            $this->state = self::STATE_COMPLETE;
            $this->dispatch(self::COMPLETE);
            $this->reset();
        }

        // Throw any exceptions that were encountered
        if ($multiException) {
            throw $multiException;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function count()
    {
        return count($this->all());
    }

    /**
     * Build a MultiTransferException if needed
     *
     * @param array $requestsInScope All requests in the previous scope
     *
     * @return MultiTransferException|null
     */
    protected function buildMultiTransferException(array $requestsInScope)
    {
        if (empty($this->exceptions)) {
            return null;
        }

        // Keep a list of all requests, and remove errored requests from the list
        $store = new \SplObjectStorage();
        foreach ($requestsInScope as $request) {
            $store->attach($request);
        }

        $multiException = new MultiTransferException('Errors during multi transfer');
        while ($e = array_shift($this->exceptions)) {
            $multiException->add($e['exception']);
            if (isset($e['request'])) {
                $multiException->addFailedRequest($e['request']);
                // Remove from the total list so that it becomes a list of successful requests
                unset($store[$e['request']]);
            }
        }

        // Add successful requests
        foreach ($store as $request) {
            $multiException->addSuccessfulRequest($request);
        }

        return $multiException;
    }

    /**
     * Prepare for sending
     *
     * @param RequestInterface $request Request to prepare
     */
    protected function beforeSend(RequestInterface $request)
    {
        try {
            $request->setState(RequestInterface::STATE_TRANSFER);
            $request->dispatch('request.before_send', array(
                'request' => $request
            ));
            if ($request->getState() != RequestInterface::STATE_TRANSFER) {
                // Requests might decide they don't need to be sent just before transfer (e.g. CachePlugin)
                $this->remove($request);
            } elseif ($request->getParams()->get('queued_response')) {
                // Queued responses do not need to be sent using curl
                $this->remove($request);
                $request->setState(RequestInterface::STATE_COMPLETE);
            } else {
                // Add the request's curl handle to the multi handle
                $this->checkCurlResult(curl_multi_add_handle($this->multiHandle, $this->createCurlHandle($request)->getHandle()));
            }
        } catch (\Exception $e) {
            $this->removeErroredRequest($request, $e);
        }
    }

    /**
     * Create a curl handle for a request
     *
     * @param RequestInterface $request Request
     *
     * @return CurlHandle
     */
    protected function createCurlHandle(RequestInterface $request)
    {
        $wrapper = CurlHandle::factory($request);
        $this->handles->attach($request, $wrapper);
        $this->resourceHash[(int) $wrapper->getHandle()] = $request;

        return $wrapper;
    }

    /**
     * Get the data from the multi handle
     */
    protected function perform()
    {
        // @codeCoverageIgnoreStart
        // Weird things can happen when making HTTP requests in __destruct methods
        if (!$this->multiHandle) {
            return;
        }
        // @codeCoverageIgnoreEnd

        // If there are no requests to send, then exit from the function
        if ($this->scope <= 0) {
            if ($this->count() == 0) {
                return;
            }
        } elseif (empty($this->requests[$this->scope])) {
            return;
        }

        // Create the polling event external to the loop
        $event = array('curl_multi' => $this);

        // Initialize the handles with a very quick select timeout
        $active = $mrc = null;
        $this->executeHandles($active, $mrc, 0.001);

        while (1) {

            $this->processMessages();

            // Exit the function if there are no more requests to send
            $scopedPolling = $this->scope <= 0 ? $this->all() : $this->requests[$this->scope];
            if (!$scopedPolling) {
                break;
            }

            // Notify each request as polling
            $blocking = $total = 0;
            foreach ($scopedPolling as $request) {
                $event['request'] = $request;
                $request->dispatch(self::POLLING_REQUEST, $event);
                ++$total;
                // The blocking variable just has to be non-falsey to block the loop
                if ($request->getParams()->hasKey(self::BLOCKING)) {
                    ++$blocking;
                }
            }

            if ($blocking == $total) {
                // Sleep to prevent eating CPU because no requests are actually pending a select call
                usleep(500);
            } else {
                do {
                    $this->executeHandles($active, $mrc, 1);
                } while ($active);
            }
        }
    }

    /**
     * Process any received curl multi messages
     */
    private function processMessages()
    {
        // Get messages from curl handles
        while ($done = curl_multi_info_read($this->multiHandle)) {
            try {
                $request = $this->resourceHash[(int) $done['handle']];
                $handle = $this->handles[$request];
                $this->processResponse($request, $handle, $done);
            } catch (\Exception $e) {
                $this->removeErroredRequest($request, $e);
            }
        }
    }

    /**
     * Execute and select curl handles until there is activity
     *
     * @param int $active  Active value to update
     * @param int $mrc     Multi result value to update
     * @param int $timeout Select timeout in seconds
     */
    private function executeHandles(&$active, &$mrc, $timeout = 1)
    {
        do {
            $mrc = curl_multi_exec($this->multiHandle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM && $active);
        $this->checkCurlResult($mrc);

        // @codeCoverageIgnoreStart
        // Select the curl handles until there is any activity on any of the open file descriptors
        // See https://github.com/php/php-src/blob/master/ext/curl/multi.c#L170
        if ($active && $mrc == CURLM_OK && curl_multi_select($this->multiHandle, $timeout) == -1) {
            // Perform a usleep if a previously executed select returned -1
            // @see https://bugs.php.net/bug.php?id=61141
            usleep(100);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Remove a request that encountered an exception
     *
     * @param RequestInterface $request Request to remove
     * @param \Exception       $e       Exception encountered
     */
    protected function removeErroredRequest(RequestInterface $request, \Exception $e)
    {
        $this->exceptions[] = array('request' => $request, 'exception' => $e);
        $this->remove($request);
        $request->setState(RequestInterface::STATE_ERROR);
        $this->dispatch(self::MULTI_EXCEPTION, array(
            'exception'      => $e,
            'all_exceptions' => $this->exceptions
        ));
    }

    /**
     * Check for errors and fix headers of a request based on a curl response
     *
     * @param RequestInterface $request Request to process
     * @param CurlHandle       $handle  Curl handle object
     * @param array            $curl    Array returned from curl_multi_info_read
     *
     * @throws CurlException on Curl error
     */
    protected function processResponse(RequestInterface $request, CurlHandle $handle, array $curl)
    {
        // Set the transfer stats on the response
        $handle->updateRequestFromTransfer($request);
        // Check if a cURL exception occurred, and if so, notify things
        $curlException = $this->isCurlException($request, $handle, $curl);

        // Always remove completed curl handles.  They can be added back again
        // via events if needed (e.g. ExponentialBackoffPlugin)
        $this->removeHandle($request);

        if (!$curlException) {
            $request->setState(RequestInterface::STATE_COMPLETE, array('handle' => $handle));
            // Only remove the request if it wasn't resent as a result of the state change
            if ($request->getState() != RequestInterface::STATE_TRANSFER) {
                $this->remove($request);
            }
        } else {
            // Set the state of the request to an error
            $request->setState(RequestInterface::STATE_ERROR);
            // Notify things that listen to the request of the failure
            $request->dispatch('request.exception', array(
                'request'   => $this,
                'exception' => $curlException
            ));

            // Allow things to ignore the error if possible
            $state = $request->getState();
            if ($state != RequestInterface::STATE_TRANSFER) {
                $this->remove($request);
            }
            // The error was not handled, so fail
            if ($state == RequestInterface::STATE_ERROR) {
                /** @var $curlException \Exception */
                throw $curlException;
            }
        }
    }

    /**
     * Remove a curl handle from the curl multi object
     *
     * Nasty things (bus errors, segmentation faults) can sometimes occur when removing curl handles when in a callback
     * or a recursive scope.  Here we are queueing all curl handles that need to be removed and closed so that this
     * happens only in the outermost scope when everything has completed sending.
     *
     * @param RequestInterface $request Request that owns the handle
     */
    protected function removeHandle(RequestInterface $request)
    {
        if ($this->handles->contains($request)) {
            $handle = $this->handles[$request];
            unset($this->resourceHash[(int) $handle->getHandle()]);
            unset($this->handles[$request]);
            $this->removeHandles[] = $handle;
        }
    }

    /**
     * Check if a cURL transfer resulted in what should be an exception
     *
     * @param RequestInterface $request Request to check
     * @param CurlHandle       $handle  Curl handle object
     * @param array            $curl    Array returned from curl_multi_info_read
     *
     * @return \Exception|bool
     */
    private function isCurlException(RequestInterface $request, CurlHandle $handle, array $curl)
    {
        if (CURLM_OK == $curl['result'] || CURLM_CALL_MULTI_PERFORM == $curl['result']) {
            return false;
        }

        $handle->setErrorNo($curl['result']);
        $e = new CurlException(sprintf('[curl] %s: %s [url] %s',
            $handle->getErrorNo(), $handle->getError(), $handle->getUrl()));
        $e->setCurlHandle($handle)
          ->setRequest($request)
          ->setCurlInfo($handle->getInfo())
          ->setError($handle->getError(), $handle->getErrorNo());

        return $e;
    }

    /**
     * Throw an exception for a cURL multi response if needed
     *
     * @param int $code Curl response code
     *
     * @throws CurlException
     */
    private function checkCurlResult($code)
    {
        if ($code != CURLM_OK && $code != CURLM_CALL_MULTI_PERFORM) {
            throw new CurlException(isset($this->multiErrors[$code])
                ? "cURL error: {$code} ({$this->multiErrors[$code][0]}): cURL message: {$this->multiErrors[$code][1]}"
                : 'Unexpected cURL error: ' . $code
            );
        }
    }

    /**
     * Create the new cURL multi handle with error checking
     */
    private function createMultiHandle()
    {
        if ($this->multiHandle && is_resource($this->multiHandle)) {
            curl_multi_close($this->multiHandle);
        }

        $this->requests = array();
        $this->multiHandle = curl_multi_init();
        $this->handles = new \SplObjectStorage();
        $this->resourceHash = array();
        $this->removeHandles = array();

        // @codeCoverageIgnoreStart
        if ($this->multiHandle === false) {
            throw new CurlException('Unable to create multi handle');
        }
        // @codeCoverageIgnoreEnd
    }
}
