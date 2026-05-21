<?php

namespace GuzzleHttp\Tests;

class Helpers
{
    public static function captureDeprecations(callable $callback): array
    {
        $deprecations = [];

        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity !== \E_USER_DEPRECATED) {
                return false;
            }

            $deprecations[] = $message;

            return true;
        });

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $deprecations;
    }

    public static function readObjectAttribute(object $object, string $attributeName)
    {
        $reflector = new \ReflectionObject($object);

        do {
            try {
                $attribute = $reflector->getProperty($attributeName);

                if (!$attribute || $attribute->isPublic()) {
                    return $object->$attributeName;
                }

                if (PHP_VERSION_ID < 80100) {
                    $attribute->setAccessible(true);
                }

                try {
                    return $attribute->getValue($object);
                } finally {
                    if (PHP_VERSION_ID < 80100) {
                        $attribute->setAccessible(false);
                    }
                }
            } catch (\ReflectionException $e) {
                // do nothing
            }
        } while ($reflector = $reflector->getParentClass());

        throw new \Exception(
            sprintf('Attribute "%s" not found in object.', $attributeName)
        );
    }

    public static function requestWithProtocolVersion(string $protocolVersion, string $uri = 'http://example.com', string $method = 'GET'): \Psr\Http\Message\RequestInterface
    {
        return new class($protocolVersion, $uri, $method) extends \GuzzleHttp\Psr7\Request {
            /** @var string */
            private $protocolVersion;

            public function __construct(string $protocolVersion, string $uri, string $method)
            {
                $this->protocolVersion = $protocolVersion;

                parent::__construct($method, $uri);
            }

            public function getProtocolVersion(): string
            {
                return $this->protocolVersion;
            }

            public function withProtocolVersion($version): \Psr\Http\Message\MessageInterface
            {
                $version = (string) $version;

                if ($this->protocolVersion === $version) {
                    return $this;
                }

                $new = clone $this;
                $new->protocolVersion = $version;

                return $new;
            }
        };
    }
}
