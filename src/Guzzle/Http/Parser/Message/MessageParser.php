<?php

namespace Guzzle\Http\Parser\Message;

/**
 * Default request and response parser used by Guzzle. Optimized for speed.
 */
class MessageParser implements MessageParserInterface
{
    /**
     * Create URL parts from HTTP message parts
     *
     * @param array $parts HTTP message parts
     *
     * @return array
     */
    public static function getUrlPartsFromMessage($requestUrl, array $parts)
    {
        // Parse the URL information from the message
        $urlParts = array(
            'path'   => $requestUrl,
            'scheme' => 'http'
        );

        // Check for the Host header
        if (isset($parts['headers']['Host'])) {
            $urlParts['host'] = $parts['headers']['Host'];
        } elseif (isset($parts['headers']['host'])) {
            $urlParts['host'] = $parts['headers']['host'];
        } else {
            $urlParts['host'] = '';
        }

        if (false === strpos($urlParts['host'], ':')) {
            $urlParts['port'] = '';
        } else {
            $hostParts = explode(':', $urlParts['host']);
            $urlParts['host'] = trim($hostParts[0]);
            $urlParts['port'] = (int) trim($hostParts[1]);
            if ($urlParts['port'] == 443) {
                $urlParts['scheme'] = 'https';
            }
        }

        // Check if a query is present
        $path = $urlParts['path'];
        $qpos = strpos($path, '?');
        if ($qpos) {
            $urlParts['query'] = substr($path, $qpos + 1);
            $urlParts['path'] = substr($path, 0, $qpos);
        } else {
            $urlParts['query'] = '';
        }

        return $urlParts;
    }

    /**
     * {@inheritdoc}
     */
    public function parseRequest($message)
    {
        if (!$message) {
            return false;
        }

        $parts = $this->parseMessage($message);

        // Parse the protocol and protocol version
        list($protocol, $version) = explode('/', $parts['start_line'][2]);

        $parsed = array(
            'method'   => strtoupper($parts['start_line'][0]),
            'protocol' => strtoupper($protocol),
            'version'  => $version,
            'headers'  => $parts['headers'],
            'body'     => $parts['body']
        );

        $parsed['request_url'] = self::getUrlPartsFromMessage($parts['start_line'][1], $parsed);

        return $parsed;
    }

    /**
     * {@inheritdoc}
     */
    public function parseResponse($message)
    {
        if (!$message) {
            return false;
        }

        $parts = $this->parseMessage($message);

        // Normalize line endings
        list($protocol, $version) = explode('/', trim($parts['start_line'][0]));

        return array(
            'protocol'      => $protocol,
            'version'       => $version,
            'code'          => trim($parts['start_line'][1]),
            'reason_phrase' => trim($parts['start_line'][2]),
            'headers'       => $parts['headers'],
            'body'          => $parts['body']
        );
    }

    /**
     * Parse a message into parts
     *
     * @param string $message Message to parse
     *
     * @return array
     */
    protected function parseMessage($message)
    {
        $startLine = null;
        $headers = array();
        $body = '';

        // Iterate over each line in the message, accounting for line endings
        $lines = preg_split('/(\\r?\\n)/', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 0, $totalLines = count($lines); $i < $totalLines; $i += 2) {

            $line = $lines[$i];

            // If two line breaks were encountered, then this is the end of body
            if (empty($line)) {
                if ($i < $totalLines - 1) {
                    $body = implode('', array_slice($lines, $i + 2));
                }
                break;
            }

            // Parse message headers
            if (!$startLine) {

                $startLine = explode(' ', $line, 3);

            } else if (strpos($line, ':')) {

                $parts = explode(':', $line, 2);
                $key = trim($parts[0]);
                $value = isset($parts[1]) ? trim($parts[1]) : '';

                if (!isset($headers[$key])) {
                    $headers[$key] = $value;
                } elseif (!is_array($headers[$key])) {
                    $headers[$key] = array($headers[$key], $value);
                } else {
                    $headers[$key][] = $value;
                }
            }
        }

        return array(
            'start_line' => $startLine,
            'headers'    => $headers,
            'body'       => $body
        );
    }
}
