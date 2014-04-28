<?php

namespace GuzzleHttp\Message;

/**
 * Request and response parser used by Guzzle
 */
class MessageParser
{
    /**
     * Parse an HTTP request message into an associative array of parts.
     *
     * @param string $message HTTP request to parse
     *
     * @return array|bool Returns false if the message is invalid
     */
    public function parseRequest($message)
    {
        if (!($parts = $this->parseMessage($message))) {
            return false;
        }

        // Parse the protocol and protocol version
        if (isset($parts['start_line'][2])) {
            $startParts = explode('/', $parts['start_line'][2]);
            $protocol = strtoupper($startParts[0]);
            $version = isset($startParts[1]) ? $startParts[1] : '1.1';
        } else {
            $protocol = 'HTTP';
            $version = '1.1';
        }

        $parsed = [
            'method'   => strtoupper($parts['start_line'][0]),
            'protocol' => $protocol,
            'protocol_version' => $version,
            'headers'  => $parts['headers'],
            'body'     => $parts['body']
        ];

        $parsed['request_url'] = $this->getUrlPartsFromMessage(
            (isset($parts['start_line'][1]) ? $parts['start_line'][1] : ''), $parsed);

        return $parsed;
    }

    /**
     * Parse an HTTP response message into an associative array of parts.
     *
     * @param string $message HTTP response to parse
     *
     * @return array|bool Returns false if the message is invalid
     */
    public function parseResponse($message)
    {
        if (!($parts = $this->parseMessage($message))) {
            return false;
        }

        list($protocol, $version) = explode('/', trim($parts['start_line'][0]));

        return [
            'protocol'         => $protocol,
            'protocol_version' => $version,
            'code'             => $parts['start_line'][1],
            'reason_phrase'    => isset($parts['start_line'][2]) ? $parts['start_line'][2] : '',
            'headers'          => $parts['headers'],
            'body'             => $parts['body']
        ];
    }

    /**
     * Parse a message into parts
     *
     * @param string $message Message to parse
     *
     * @return array|bool
     */
    private function parseMessage($message)
    {
        if (!$message) {
            return false;
        }

        $startLine = null;
        $headers = [];
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
            } elseif (strpos($line, ':')) {
                $parts = explode(':', $line, 2);
                $key = trim($parts[0]);
                $value = isset($parts[1]) ? trim($parts[1]) : '';
                if (!isset($headers[$key])) {
                    $headers[$key] = $value;
                } elseif (!is_array($headers[$key])) {
                    $headers[$key] = [$headers[$key], $value];
                } else {
                    $headers[$key][] = $value;
                }
            }
        }

        return [
            'start_line' => $startLine,
            'headers'    => $headers,
            'body'       => $body
        ];
    }

    /**
     * Create URL parts from HTTP message parts
     *
     * @param string $requestUrl Associated URL
     * @param array  $parts      HTTP message parts
     *
     * @return array
     */
    private function getUrlPartsFromMessage($requestUrl, array $parts)
    {
        // Parse the URL information from the message
        $urlParts = ['path' => $requestUrl, 'scheme' => 'http'];

        // Check for the Host header
        if (isset($parts['headers']['Host'])) {
            $urlParts['host'] = $parts['headers']['Host'];
        } elseif (isset($parts['headers']['host'])) {
            $urlParts['host'] = $parts['headers']['host'];
        } else {
            $urlParts['host'] = null;
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
}
