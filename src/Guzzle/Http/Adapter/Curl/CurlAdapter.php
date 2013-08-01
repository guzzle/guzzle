<?php

namespace Guzzle\Http\Adapter\Curl;

use Guzzle\Http\Adapter\AbstractAdapter;
use Guzzle\Http\Adapter\Transaction;
use Guzzle\Http\Exception\AdapterException;
use Guzzle\Http\Exception\RequestException;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Stream\Stream;

/**
 * HTTP adapter that uses cURL as a transport layer
 */
class CurlAdapter extends AbstractAdapter
{
    /** @var CurlFactory */
    private $factory;

    /** @var array Array of curl multi handles */
    private $multiHandles = array();

    /** @var array Array of curl multi handles */
    private $multiOwned = array();

    /** @var array cURL multi error values and codes */
    private static $multiErrors = array(
        CURLM_BAD_HANDLE      => array('CURLM_BAD_HANDLE', 'The passed-in handle is not a valid CURLM handle.'),
        CURLM_BAD_EASY_HANDLE => array('CURLM_BAD_EASY_HANDLE', "An easy handle was not good/valid. It could mean that it isn't an easy handle at all, or possibly that the handle already is in used by this or another multi handle."),
        CURLM_OUT_OF_MEMORY   => array('CURLM_OUT_OF_MEMORY', 'You are doomed.'),
        CURLM_INTERNAL_ERROR  => array('CURLM_INTERNAL_ERROR', 'This can only be returned if libcurl bugs. Please report it to us!')
    );

    public function __destruct()
    {
        foreach ($this->multiHandles as $handle) {
            if (is_resource($handle)) {
                curl_multi_close($handle);
            }
        }
    }

    protected function init(array $options)
    {
        $this->factory = isset($options['factory']) ? $options['factory'] : CurlFactory::getInstance();
    }

    public function send(array $requests)
    {
        $context = [
            'transaction' => new Transaction(),
            'handles'     => new \SplObjectStorage(),
            'multi'       => $this->checkoutMultiHandle()
        ];

        foreach ($requests as $request) {
            try {
                $this->prepare($request, $context);
            } catch (RequestException $e) {
                $context['transaction'][$request] = $e;
            }
        }

        $this->perform($context);
        $this->releaseMultiHandle($context['multi']);

        return $context['transaction'];
    }

    private function prepare(RequestInterface $request, array $context)
    {
        $response = $this->messageFactory->createResponse();
        $handle = $this->factory->createHandle($request, $response);
        $this->checkCurlResult(curl_multi_add_handle($context['multi'], $handle));
        $context['handles'][$request] = $handle;
        $context['transaction'][$request] = $response;
    }

    /**
     * Execute and select curl handles
     *
     * @param array $context Transaction context
     */
    private function perform(array $context)
    {
        // The first curl_multi_select often times out no matter what, but is usually required for fast transfers
        $selectTimeout = 0.001;
        $active = false;
        do {
            while (($mrc = curl_multi_exec($context['multi'], $active)) == CURLM_CALL_MULTI_PERFORM);
            $this->checkCurlResult($mrc);
            $this->processMessages($context);
            if ($active && curl_multi_select($context['multi'], $selectTimeout) === -1) {
                // Perform a usleep if a select returns -1: https://bugs.php.net/bug.php?id=61141
                usleep(150);
            }
            $selectTimeout = 1;
        } while ($active);
    }

    /**
     * Check for errors and fix headers of a request based on a curl response
     *
     * @param RequestInterface  $request Request to process
     * @param array             $curl    Curl data
     * @param array             $context Array of context information of the transfer
     *
     * @throws RequestException on error
     */
    private function processResponse(RequestInterface $request, array $curl, array $context)
    {
        if (isset($context['handles'][$request])) {
            curl_multi_remove_handle($context['multi'], $context['handles'][$request]);
            curl_close($context['handles'][$request]);
            unset($context['handles'][$request]);
        }

        try {
            $this->isCurlException($request, $curl);
            // Emit request.sent
        } catch (RequestException $e) {
            $context['transaction'][$request] = $e;
        }
    }

    /**
     * Process any received curl multi messages
     */
    private function processMessages(array $context)
    {
        while ($done = curl_multi_info_read($context['multi'])) {
            foreach ($context['handles'] as $request) {
                if ($context['handles'][$request] === $done['handle']) {
                    $this->processResponse($request, $done, $context);
                    continue 2;
                }
            }
        }
    }

    /**
     * Check if a cURL transfer resulted in what should be an exception
     *
     * @param RequestInterface $request Request to check
     * @param array            $curl    Array returned from curl_multi_info_read
     *
     * @throws RequestException|bool
     */
    private function isCurlException(RequestInterface $request, array $curl)
    {
        if (CURLM_OK == $curl['result'] || CURLM_CALL_MULTI_PERFORM == $curl['result']) {
            return;
        }

        // Emit request.error?

        throw new RequestException(
            sprintf('[curl] Error code %s [url] %s', $curl['result'], $request->getUrl()),
            $request
        );
    }

    /**
     * Throw an exception for a cURL multi response if needed
     *
     * @param int $code Curl response code
     * @throws AdapterException
     */
    private function checkCurlResult($code)
    {
        if ($code != CURLM_OK && $code != CURLM_CALL_MULTI_PERFORM) {
            throw new AdapterException(isset(self::$multiErrors[$code])
                ? sprintf('cURL error %s: %s (%s)', $code, self::$multiErrors[$code][0], self::$multiErrors[$code][1])
                : 'Unexpected cURL error: ' . $code
            );
        }
    }

    /**
     * Returns a curl_multi handle from the cache or creates a new one
     *
     * @return resource
     */
    private function checkoutMultiHandle()
    {
        // Find an unused handle in the cache
        if (false !== ($key = array_search(false, $this->multiOwned, true))) {
            $this->multiOwned[$key] = true;
            return $this->multiHandles[$key];
        }

        // Add a new handle
        $handle = curl_multi_init();
        $this->multiHandles[(int) $handle] = $handle;
        $this->multiOwned[(int) $handle] = true;

        return $handle;
    }

    /**
     * Releases a curl_multi handle back into the cache and removes excess cache
     *
     * @param resource $handle Curl multi handle to remove
     */
    private function releaseMultiHandle($handle)
    {
        $this->multiOwned[(int) $handle] = false;
        // Prune excessive handles
        $over = count($this->multiHandles) - 3;
        while (--$over > -1) {
            curl_multi_close(array_pop($this->multiHandles));
            array_pop($this->multiOwned);
        }
    }
}
