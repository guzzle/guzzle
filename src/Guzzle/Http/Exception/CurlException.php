<?php

namespace Guzzle\Http\Exception;

/**
 * cURL request exception
 */
class CurlException extends BadResponseException
{
    private $curlError;
    private $curlErrorNo;

    /**
     * Set the cURL error message
     *
     * @param string $error Curl error
     * @param int $number Curl error number
     */
    public function setError($error, $number)
    {
        $this->curlError = $error;
        $this->curlErrorNo = $number;

        return $this;
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
