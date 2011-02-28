<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

/**
 * Deletes a domain and the recreates the domain, thus truncating the data
 * in the domain.
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_GetAttributes.html
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class TruncateDomain extends AbstractSimpleDbCommandRequiresDomain
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'DeleteDomain';

    /**
     * {@inheritdoc}
     */
    protected $canBatch = false;

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $command = new CreateDomain();
        $command->setDomain($this->get('domain'));
        $this->getClient()->execute($command);
        $this->result = $command->getResult();
    }
}