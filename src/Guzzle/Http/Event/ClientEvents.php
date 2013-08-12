<?php

namespace Guzzle\Http\Event;

final class ClientEvents
{
    /**
     * Event emitted when a request is created
     *
     * The event context contains a 'request' key
     */
    const CREATE_REQUEST = 'client.create_request';
}
