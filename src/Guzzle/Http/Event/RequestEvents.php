<?php

namespace Guzzle\Http\Event;

final class RequestEvents
{
    /**
     * Event emitted before a request is sent
     *
     * The event emitted is a {@see \Guzzle\Http\Event\RequestBeforeSendEvent} object
     */
    const BEFORE_SEND = 'request.before_send';

    /**
     * Event emitted when a request has finished sending
     *
     * The event emitted is a {@see \Guzzle\Http\Event\RequestAfterSendEvent} object
     */
    const AFTER_SEND = 'request.after_send';

    /**
     * Event emitted when an error occurs for a given request
     *
     * The event emitted is a {@see \Guzzle\Http\Event\RequestErrorEvent} object
     */
    const ERROR = 'request.error';

    /**
     * Event emitted after receiving all of the headers of a non-information response.
     *
     * The event context contains 'request' and 'response' keys.
     */
    const RESPONSE_HEADERS = 'request.response_headers';
}
