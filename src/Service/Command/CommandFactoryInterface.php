<?php

namespace GuzzleHttp\Service\Command;

/**
 * Interface for creating commands by name
 */
interface CommandFactoryInterface
{
    /**
     * Create a command by name
     *
     * @param string $name Command to create
     * @param array  $args Command arguments
     *
     * @return CommandInterface|null
     */
    public function factory($name, array $args = []);
}
