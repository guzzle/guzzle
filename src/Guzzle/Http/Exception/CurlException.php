<?php

namespace Guzzle\Http\Exception;

use Guzzle\Http\Curl\CurlHandle;

/**
 * cURL request exception
 */
class CurlException extends BadResponseException
{
    private $curlError;
    private $curlErrorNo;
    private $handle;

    /**
     * Set the cURL error message
     *
     * @param string $error  Curl error
     * @param int    $number Curl error number
     *
     * @return self
     */
    public function setError($error, $number)
    {
        $this->curlError = $error;
        $this->curlErrorNo = $number;

        return $this;
    }

    /**
     * Set the associated curl handle
     *
     * @param CurlHandle $handle Curl handle
     *
     * @return self
     */
    public function setCurlHandle(CurlHandle $handle)
    {
        $this->handle = $handle;

        return $this;
    }

    /**
     * Get the associated cURL handle
     *
     * @return CurlHandle|null
     */
    public function getCurlHandle()
    {
        return $this->handle;
    }

    /**
     * Get the associated cURL error message
     *
     * @return string
     */
    public function getError()
    {
        return $this->curlError;
    }

    /**
     * Get the associated cURL error number
     *
     * @return int
     */
    public function getErrorNo()
    {
        return $this->curlErrorNo;
    }
}
