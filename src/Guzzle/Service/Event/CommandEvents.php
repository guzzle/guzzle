<?php

namespace Guzzle\Service\Event;

/**
 * Events emitted when executing commands
 */
final class CommandEvents
{
    /**
     * Event emitted when a command is being prepared into a request
     *
     * The event emitted is a {@see \Guzzle\Service\CommandPrepareEvent} object
     */
    const PREPARE = 'command.prepare';

    /**
     * Event emitted when the request for a command has been sent and is ready to
     * be processed.
     *
     * The event emitted is a {@see \Guzzle\Service\CommandProcessEvent} object
     */
    const PROCESS = 'command.process';

    /**
     * Event emitted when an error occurs for a given command
     *
     * The event emitted is a {@see \Guzzle\Service\Event\CommandErrorEvent} object
     */
    const ERROR = 'command.error';
}
