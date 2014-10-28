<?php
namespace GuzzleHttp\Message;

/**
 * Represents an HTTP response message.
 */
interface ResponseInterface extends MessageInterface
{
    /**
     * Gets the response Status-Code.
     *
     * The Status-Code is a 3-digit integer result code of the server's attempt
     * to understand and satisfy the request.
     *
     * @return int Status code.
     */
    public function getStatusCode();

    /**
     * Sets the status code of this response.
     *
     * @param int $code The 3-digit integer result code to set.
     */
    public function setStatusCode($code);

    /**
     * Gets the response Reason-Phrase, a short textual description of the
     * Status-Code.
     *
     * Because a Reason-Phrase is not a required element in response
     * Status-Line, the Reason-Phrase value MAY be null. Implementations MAY
     * choose to return the default RFC 2616 recommended reason phrase for the
     * response's Status-Code.
     *
     * @return string|null Reason phrase, or null if unknown.
     */
    public function getReasonPhrase();

    /**
     * Sets the Reason-Phrase of the response.
     *
     * If no Reason-Phrase is specified, implementations MAY choose to default
     * to the RFC 2616 recommended reason phrase for the response's Status-Code.
     *
     * @param string $phrase The Reason-Phrase to set.
     */
    public function setReasonPhrase($phrase);

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
     *     - libxml_options: Bitwise OR of the libxml option constants
     *       listed at http://php.net/manual/en/libxml.constants.php
     *       (defaults to LIBXML_NONET)
     *
     * @return \SimpleXMLElement
     * @throws \RuntimeException if the response body is not in XML format
     * @link http://websec.io/2012/08/27/Preventing-XXE-in-PHP.html
     */
    public function xml(array $config = []);
}
