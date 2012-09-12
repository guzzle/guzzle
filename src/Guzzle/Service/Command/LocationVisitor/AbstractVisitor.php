<?php

namespace Guzzle\Service\Command\LocationVisitor;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Description\ApiParam;
use Guzzle\Service\Command\CommandInterface;

/**
 * Default shared behavior for location visitors
 */
abstract class AbstractVisitor implements LocationVisitorInterface
{
    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function after(CommandInterface $command, RequestInterface $request) {}

    /**
     * {@inheritdoc}
     * @codeCoverageIgnore
     */
    public function visit(CommandInterface $command, RequestInterface $request, $key, $value, ApiParam $param = null) {}

    /**
     * Map nested parameters into the location_key based parameters
     *
     * @param array    $value Value to map
     * @param ApiParam $param Parameter that holds information about the current key
     *
     * @return array Returns the mapped array
     */
    protected function resolveRecursively(array $value, ApiParam $param)
    {
        foreach ($value as $name => $v) {
            if ($subParam = $param->getStructure($name)) {
                $key = $subParam->getLocationKey();
                if (is_array($v)) {
                    $value[$key] = $this->resolveRecursively($v, $subParam);
                } elseif ($name != $key) {
                    $value[$key] = $v;
                    unset($value[$name]);
                }
            }
        }

        return $value;
    }
}
