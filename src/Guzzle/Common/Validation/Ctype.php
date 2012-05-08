<?php

namespace Guzzle\Common\Validation;

/**
 * Ensures that a value is of a specific ctype_*
 */
class Ctype extends AbstractType
{
    /**
     * @var array Mapping of types to methods used to check the type
     */
    protected static $typeMapping = array(
        'alnum'  => 'ctype_alnum',
        'alpha'  => 'ctype_alpha',
        'cntrl'  => 'ctype_cntrl',
        'digit'  => 'ctype_digit',
        'graph'  => 'ctype_graph',
        'lower'  => 'ctype_lower',
        'print'  => 'ctype_print',
        'punct'  => 'ctype_punct',
        'space'  => 'ctype_space',
        'upper'  => 'ctype_upper',
        'xdigit' => 'ctype_xdigit'
    );
}
