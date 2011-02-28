<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

/**
 * Returns information about the domain, including when the domain was created,
 * the number of items and attributes, and the size of attribute names and
 * values.
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_DomainMetadata.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle domain required="true"
 */
class DomainMetadata extends AbstractSimpleDbCommandRequiresDomain
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'DomainMetadata';
}