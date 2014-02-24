<?php

namespace GuzzleHttp\Service\Guzzle\Subscriber;

use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Service\CommandException;
use GuzzleHttp\Service\Guzzle\Description\SchemaValidator;
use GuzzleHttp\Service\Guzzle\GuzzleCommandInterface;
use GuzzleHttp\Service\PrepareEvent;

/**
 * Subscriber used to validate command input against a service description.
 */
class ValidateInput implements SubscriberInterface
{
    /** @var SchemaValidator */
    private $validator;

    public static function getSubscribedEvents()
    {
        return ['prepare' => ['onPrepare']];
    }

    public function __construct(SchemaValidator $schemaValidator = null)
    {
        $this->validator = $schemaValidator ?: new SchemaValidator();
    }

    public function onPrepare(PrepareEvent $event)
    {
        $command = $event->getCommand();
        if (!($command instanceof GuzzleCommandInterface)) {
            throw new \RuntimeException('Invalid command sent to validator');
        }

        $errors = [];
        $operation = $command->getOperation();
        foreach ($operation->getParams() as $name => $schema) {
            $value = $command[$name];
            if (!$this->validator->validate($schema, $value)) {
                $errors = array_merge($errors, $this->validator->getErrors());
            } elseif ($value !== $command[$name]) {
                // Update the config value if it changed and no validation
                // errors were encountered
                $command[$name] = $value;
            }
        }

        if ($params = $operation->getAdditionalParameters()) {
            foreach ($command as $name => $value) {
                // It's only additional if it isn't defined in the schema
                if (!$operation->hasParam($name)) {
                    // Always set the name so that error messages are useful
                    $params->setName($name);
                    if (!$this->validator->validate($params, $value)) {
                        $errors = array_merge(
                            $errors,
                            $this->validator->getErrors()
                        );
                    } elseif ($value !== $command[$name]) {
                        $command[$name] = $value;
                    }
                }
            }
        }

        if ($errors) {
            throw new CommandException(
                'Validation errors: ' . implode("\n", $errors),
                $event->getClient(),
                $command
            );
        }
    }
}
