<?php

declare(strict_types=1);

namespace GuzzleHttp\Tests;

class Helpers
{
    /**
     * @return mixed
     */
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
}
