<?php

namespace Guzzle\Http\Parser\Message;

/**
 * Pecl HTTP message parser
 */
class PeclHttpMessageParser implements MessageParserInterface
{
    /**
     * {@inheritdoc}
     */
    public function parseRequest($message)
    {
        if (!$message) {
            return false;
        }

        $parts = http_parse_message($message);

        $parsed = array(
            'method'   => $parts->requestMethod,
            'protocol' => 'HTTP',
            'version'  => number_format($parts->httpVersion, 1),
            'headers'  => $parts->headers,
            'body'     => $parts->body
        );

        $parsed['request_url'] = MessageParser::getUrlPartsFromMessage($parts->requestUrl, $parsed);

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

        $parts = http_parse_message($message);

        return array(
            'protocol'      => 'HTTP',
            'version'       => number_format($parts->httpVersion, 1),
            'code'          => $parts->responseCode,
            'reason_phrase' => $parts->responseStatus,
            'headers'       => $parts->headers,
            'body'          => $parts->body
        );
    }
}
