<?php

namespace Guzzle\Http\Adapter;

use Guzzle\Http\Message\MessageFactoryInterface;

abstract class AbstractAdapter implements AdapterInterface
{
    protected $messageFactory;

    /**
     * @param MessageFactoryInterface $messageFactory Factory used to create responses
     * @param array                   $options        Adapter options
     */
    public function __construct(MessageFactoryInterface $messageFactory, array $options = [])
    {
        $this->messageFactory = $messageFactory;
        $this->init($options);
    }

    /**
     * Initialization hook
     */
    protected function init(array $options) {}
}
