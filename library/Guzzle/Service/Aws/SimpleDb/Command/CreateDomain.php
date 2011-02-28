<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

/**
 * Create an Amazon SimpleDB domain
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_CreateDomain.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle domain required="true"
 */
class CreateDomain extends AbstractSimpleDbCommandRequiresDomain
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'CreateDomain';
}