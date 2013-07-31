<?php

namespace Guzzle\Http\Message;

interface ResponseInterface extends MessageInterface
{
    /**
     * Set the response status
     *
     * @param int    $statusCode   Response status code to set
     * @param string $reasonPhrase Response reason phrase
     *
     * @return self
     */
    public function setStatus($statusCode, $reasonPhrase = null);

    /**
     * Get the response status code
     *
     * @return integer
     */
    public function getStatusCode();

    /**
     * Get the response reason phrase- a human readable version of the numeric
     * status code
     *
     * @return string
     */
    public function getReasonPhrase();

    /**
     * Checks if HTTP Status code is Information (1xx)
     *
     * @return bool
     */
    public function isInformational();

    /**
     * Checks if HTTP Status code is Successful (2xx)
     *
     * @return bool
     */
    public function isSuccessful();

    /**
     * Checks if HTTP Status code is a Redirect (3xx)
     *
     * @return bool
     */
    public function isRedirect();

    /**
     * Checks if HTTP Status code is a Client Error (4xx)
     *
     * @return bool
     */
    public function isClientError();

    /**
     * Checks if HTTP Status code is Server Error (5xx)
     *
     * @return bool
     */
    public function isServerError();

    /**
     * Get the effective URL that resulted in this response (e.g. the last redirect URL)
     *
     * @return string
     */
    public function getEffectiveUrl();

    /**
     * Parse the JSON response body and return an array
     *
     * @return array|string|int|bool|float
     * @throws \RuntimeException if the response body is not in JSON format
     */
    public function json();

    /**
     * Parse the XML response body and return a SimpleXMLElement
     *
     * @return \SimpleXMLElement
     * @throws \RuntimeException if the response body is not in XML format
     */
    public function xml();
}
