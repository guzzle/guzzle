<?php

namespace Guzzle\Http\Curl;

use Guzzle\Http\Message\BadResponseException;

/**
 * cURL request exception
 *
 * @author  michael@guzzlephp.org
 */
class CurlException extends BadResponseException
{
    /**
     * @var string
     */
    private $curlError;

    /**
     * Set the cURL error message
     *
     * @param string $error Curl error
     */
    public function setCurlError($error)
    {
        $this->curlError = $error;

        return $this;
    }

    /**
     * Get the associated cURL error message
     *
     * @return string
     */
    public function getCurlError()
    {
        return $this->curlError;
    }
}