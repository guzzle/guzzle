<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

use Guzzle\Service\Command\AbstractCommand;

/**
 * Delete an Amazon SimpleDB domain
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_DeleteDomain.html
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractSimpleDbCommand extends AbstractCommand
{
    /**
     * @var string The action to take on the API
     */
    protected $action;

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $this->result = new \SimpleXMLElement($this->getResponse()->getBody(true));
    }

    /**
     * Returns a SimpleXMLElement
     *
     * @return \SimpleXMLElement
     */
    public function getResult()
    {
        return parent::getResult();
    }
}