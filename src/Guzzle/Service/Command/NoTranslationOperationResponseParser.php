<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Description\Parameter;

/**
 * Response parser that will not walk a model structure, but does implement native parsing and creating model objects
 * @codeCoverageIgnore
 */
class NoTranslationOperationResponseParser extends OperationResponseParser
{
    /**
     * {@inheritdoc}
     */
    protected static $instance;

    /**
     * {@inheritdoc}
     */
    protected function visitResult(
        Parameter $model,
        CommandInterface $command,
        Response $response,
        array &$result,
        $context = null
    ) {}
}
