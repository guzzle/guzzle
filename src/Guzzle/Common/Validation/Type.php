<?php

namespace Guzzle\Common\Validation;

/**
 * Ensures that a value is of a specific type
 * @codeCoverageIgnore
 */
class Type extends AbstractType
{
    /**
     * @var array Mapping of types to methods used to check the type
     */
    protected static $typeMapping = array(
        'array'    => 'is_array',
        'long'     => 'is_long',
        'bool'     => 'is_bool',
        'boolean'  => 'is_bool',
        'scalar'   => 'is_scalar',
        'string'   => 'is_string',
        'nan'      => 'is_nan',
        'float'    => 'is_float',
        'file'     => 'is_file',
        'callable' => 'is_callable',
        'null'     => 'is_null',
        'resource' => 'is_resource',
        'numeric'  => 'is_numeric',
        'object'   => 'is_object'
    );
}
