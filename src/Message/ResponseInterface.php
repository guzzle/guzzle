<?php

namespace GuzzleHttp\Message;

/**
 * Represents an HTTP response message.
 */
interface ResponseInterface extends MessageInterface
{
    /**
     * Get the response status code (e.g. "200", "404", etc.)
     *
     * @return string
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
     * Get the effective URL that resulted in this response (e.g. the last
     * redirect URL).
     *
     * @return string
     */
    public function getEffectiveUrl();

    /**
     * Set the effective URL that resulted in this response (e.g. the last
     * redirect URL).
     *
     * @param string $url Effective URL
     *
     * @return self
     */
    public function setEffectiveUrl($url);

    /**
     * Parse the JSON response body and return the JSON decoded data.
     *
     * @param array $config Associative array of configuration settings used
     *     to control how the JSON data is parsed. Concrete implementations MAY
     *     add further configuration settings as needed, but they MUST implement
     *     functionality for the following options:
     *
     *     - object: Set to true to parse JSON objects as PHP objects rather
     *       than associative arrays. Defaults to false.
     *     - big_int_strings: When set to true, large integers are converted to
     *       strings rather than floats. Defaults to false.
     *
     *     Implementations are free to add further configuration settings as
     *     needed.
     *
     * @return mixed Returns the JSON decoded data based on the provided
     *     parse settings.
     * @throws \RuntimeException if the response body is not in JSON format
     */
    public function json(array $config = []);

    /**
     * Parse the XML response body and return a \SimpleXMLElement.
     *
     * In order to prevent XXE attacks, this method disables loading external
     * entities. If you rely on external entities, then you must parse the
     * XML response manually by accessing the response body directly.
     *
     * @param array $config Associative array of configuration settings used
     *     to control how the XML is parsed. Concrete implementations MAY add
     *     further configuration settings as needed, but they MUST implement
     *     functionality for the following options:
     *
     *     - ns: Set to a string to represent the namespace prefix or URI
     *     - ns_is_prefix: Set to true to specify that the NS is a prefix rather
     *       than a URI (defaults to false).
     *
     * @return \SimpleXMLElement
     * @throws \RuntimeException if the response body is not in XML format
     * @link http://websec.io/2012/08/27/Preventing-XXE-in-PHP.html
     */
    public function xml(array $config = []);
}
