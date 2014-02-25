<?php

namespace GuzzleHttp\Service\Event;

use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\HasEmitterTrait;
use GuzzleHttp\Message\RequestInterface;
use GuzzleHttp\Message\ResponseInterface;
use GuzzleHttp\Service\CommandInterface;
use GuzzleHttp\Service\ServiceClientInterface;
use GuzzleHttp\Service\CommandException;

/**
 * Utility class used to wrap HTTP events with client events.
 */
class EventWrapper
{
    /**
     * Handles the workflow of a command before it is sent.
     *
     * This includes preparing a request for the command, hooking the command
     * event system up to the request's event system, and returning the
     * prepared request.
     *
     * @param CommandInterface       $command Command to prepare
     * @param ServiceClientInterface $client  Client that executes the command
     *
     * @return PrepareEvent returns the PrepareEvent. You can use this to see
     *     if the event was intercepted with a result, or to grab the request
     *     that was prepared for the event.
     *
     * @throws \RuntimeException
     */
    public static function prepareCommand(
        CommandInterface $command,
        ServiceClientInterface $client
    ) {
        $event = self::prepareEvent($command, $client);
        $request = $event->getRequest();

        if ($request) {
            self::injectErrorHandler($command, $client, $request);
        } elseif ($event->getResult() === null) {
            throw new \RuntimeException('No request was prepared for the '
                . 'command and no result was added to intercept the event. One '
                . 'of the listeners must set a request on the prepare event.');
        }

        return $event;
    }

    /**
     * Handles the processing workflow of a command after it has been sent and
     * a response has been received.
     *
     * @param CommandInterface       $command  Command that was executed
     * @param ServiceClientInterface $client   Client that sent the command
     * @param RequestInterface       $request  Request that was sent
     * @param ResponseInterface      $response Response that was received
     * @param mixed                  $result   Specify the result if available
     *
     * @return mixed|null Returns the result of the command
     */
    public static function processCommand(
        CommandInterface $command,
        ServiceClientInterface $client,
        RequestInterface $request,
        ResponseInterface $response = null,
        $result = null
    ) {
        $event = new ProcessEvent($command, $client, $request, $response, $result);
        $command->getEmitter()->emit('process', $event);

        return $event->getResult();
    }

    /**
     * Prepares a command for sending and returns the prepare event.
     */
    private static function prepareEvent(
        CommandInterface $command,
        ServiceClientInterface $client
    ) {
        try {
            $event = new PrepareEvent($command, $client);
            $command->getEmitter()->emit('prepare', $event);
            return $event;
        } catch (CommandException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new CommandException(
                'Error preparing command: ' . $e->getMessage(),
                $client,
                $command,
                null,
                null,
                $e
            );
        }
    }

    /**
     * Wrap HTTP level errors with command level errors.
     */
    private static function injectErrorHandler(
        CommandInterface $command,
        ServiceClientInterface $client,
        RequestInterface $request
    ) {
        $request->getEmitter()->on(
            'error',
            function (ErrorEvent $e) use ($command, $client) {
                $event = new CommandErrorEvent($command, $client, $e);
                $command->getEmitter()->emit('error', $event);

                if ($event->getResult() === null) {
                    throw new CommandException(
                        'Error executing command: ' . $e->getException()->getMessage(),
                        $client,
                        $command,
                        $e->getRequest(),
                        $e->getResponse(),
                        $e->getException()
                    );
                }

                $e->stopPropagation();
                self::processCommand(
                    $command,
                    $client,
                    $event->getRequest(),
                    null,
                    $event->getResult()
                );
            }
        );
    }
}
