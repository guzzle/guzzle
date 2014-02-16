<?php

namespace Guzzle\Subscriber\Log;

use Guzzle\Common\EventSubscriberInterface;
use Guzzle\Http\Event\CompleteEvent;
use Guzzle\Http\Event\ErrorEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Plugin class that will add request and response logging to an HTTP request.
 *
 * The log plugin uses a message formatter that allows custom messages via template variable substitution.
 *
 * @see MessageLogger for a list of available log template variable substitutions
 */
class LogSubscriber implements EventSubscriberInterface
{
    /** @var LoggerInterface */
    private $logger;

    /** @var MessageFormatter Formatter used to format messages before logging */
    private $formatter;

    /**
     * @param LoggerInterface         $logger     Logger used to log messages
     * @param string|MessageFormatter $formatter  Formatter used to format log messages or the formatter template
     */
    public function __construct(LoggerInterface $logger, $formatter = null)
    {
        $this->logger = $logger;
        $this->formatter = $formatter instanceof MessageFormatter ? $formatter : new MessageFormatter($formatter);
    }

    /**
     * Get a log plugin that outputs full request, response, and any error messages
     *
     * @param resource $stream Stream to write to when logging. Defaults to STDOUT
     *
     * @return self
     */
    public static function getDebugPlugin($stream = null)
    {
        return new self(new SimpleLogger($stream), "# Request:\n{request}\n# Response:\n{response}\n{error}");
    }

    public static function getSubscribedEvents()
    {
        return [
            'complete' => ['onRequestAfterSend', -9999],
            'error'    => ['onRequestError', 9999]
        ];
    }

    /**
     * @param CompleteEvent $event
     */
    public function onRequestAfterSend(CompleteEvent $event)
    {
        $this->logger->log(
            substr($event->getResponse()->getStatusCode(), 0, 1) == '2' ? LogLevel::INFO : LogLevel::WARNING,
            $this->formatter->format($event->getRequest(), $event->getResponse()),
            ['request' => $event->getRequest(), 'response' => $event->getResponse()]
        );
    }

    /**
     * @param ErrorEvent $event
     */
    public function onRequestError(ErrorEvent $event)
    {
        $ex = $event->getException();
        $this->logger->log(
            LogLevel::CRITICAL,
            $this->formatter->format($event->getRequest(), $event->getResponse(), $ex),
            ['request' => $event->getRequest(), 'response' => $event->getResponse(), 'exception' => $ex]
        );
    }
}
