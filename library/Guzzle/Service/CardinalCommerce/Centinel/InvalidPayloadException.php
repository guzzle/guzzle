<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\CardinalCommerce\Centinel;

use Guzzle\Common\GuzzleException;

/**
 * Exception for an invalid payload
 *
 * @author Michael Dowling <michael@shoebacca.com>
 */
class InvalidPayloadException extends \InvalidArgumentException implements GuzzleException
{
}