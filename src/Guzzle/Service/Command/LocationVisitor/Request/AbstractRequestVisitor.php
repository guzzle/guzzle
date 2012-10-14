<?php

namespace Guzzle\Service\Command\LocationVisitor\Request;

use Guzzle\Service\Command\CommandInterface;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Description\Parameter;

/**
 * {@inheritdoc}
 */
abstract class AbstractRequestVisitor implements RequestVisitorInterface
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
    public function visit(CommandInterface $command, RequestInterface $request, Parameter $param, $value) {}

    /**
     * Map nested parameters into the location_key based parameters
     *
     * @param array     $value Value to map
     * @param Parameter $param Parameter that holds information about the current key
     *
     * @return array Returns the mapped array
     */
    protected function resolveRecursively(array $value, Parameter $param)
    {
        foreach ($value as $name => $v) {
            if ($subParam = $param->getProperty($name)) {
                $key = $subParam->getWireName();
                if (is_array($v)) {
                    $value[$key] = $this->resolveRecursively($v, $subParam);
                } elseif ($name != $key) {
                    $value[$key] = $param->filter($v);
                    unset($value[$name]);
                }
            }
        }

        return $value;
    }
}
