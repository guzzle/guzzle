<?php

namespace GuzzleHttp\Handler;

class CurlHandlePool implements CurlHandlePoolInterface
{
    /**
     * @var resource[]|\CurlHandle[]
     */
    private $handles = [];

    /**
     * @var int Total number of idle handles to keep in cache
     */
    private $maxHandles;

    /**
     * @param int $maxHandles Maximum number of idle handles.
     */
    public function __construct(int $maxHandles)
    {
        $this->maxHandles = $maxHandles;
    }

    public function get()
    {
        return $this->handles ? \array_pop($this->handles) : \curl_init();
    }

    public function put($handle): void
    {
        if (\count($this->handles) >= $this->maxHandles) {
            \curl_close($handle);
        } else {
            // Remove all callback functions as they can hold onto references
            // and are not cleaned up by curl_reset. Using curl_setopt_array
            // does not work for some reason, so removing each one
            // individually.
            \curl_setopt($handle, \CURLOPT_HEADERFUNCTION, null);
            \curl_setopt($handle, \CURLOPT_READFUNCTION, null);
            \curl_setopt($handle, \CURLOPT_WRITEFUNCTION, null);
            \curl_setopt($handle, \CURLOPT_PROGRESSFUNCTION, null);
            \curl_reset($handle);
            $this->handles[] = $handle;
        }
    }

    public function __destruct()
    {
        foreach ($this->handles as $id => $handle) {
            \curl_close($handle);
            unset($this->handles[$id]);
        }
    }
}
