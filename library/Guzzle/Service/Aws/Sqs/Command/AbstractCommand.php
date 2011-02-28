<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs\Command;

use Guzzle\Service\Command\AbstractCommand as DefaultCommand;

/**
 * Abstract Amazon SQS command
 * 
 * @author Michael Dowling <michael@guzzlephp.org>
 */
abstract class AbstractCommand extends DefaultCommand
{
    /**
     * @var SimpleXMLElement Result of the call
     */
    protected $xmlResult;

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $this->xmlResult = $this->result = new \SimpleXMLElement($this->getResponse()->getBody(true));
    }

    /**
     * Returns the XML body of the request
     *
     * @return SimpleXMLElement
     */
    public function getResult()
    {
        return parent::getResult();
    }

    /**
     * Get the SQS request ID
     *
     * @return string|null
     */
    public function getRequestId()
    {
        return !$this->xmlResult ? null : trim((string)$this->xmlResult->ResponseMetadata->RequestId);
    }
}