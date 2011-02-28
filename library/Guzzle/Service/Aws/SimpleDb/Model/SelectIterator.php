<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Model;

use Guzzle\Service\ResourceIterator;
use Guzzle\Service\Aws\SimpleDb\Command\Select;
use Guzzle\Service\Aws\SimpleDb\SimpleDbClient;

/**
 * Iterates over the items in a domain using the Select action
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class SelectIterator extends ResourceIterator
{
    /**
     * Factory method to create a SelectIterator from a Select operation's
     * result
     *
     * @param SimpleDbClient $client Client responsible for sending subsquent requests
     * @param SimpleXMLElement $selectResult The initial XML response from a
     *      select command
     * @param array $data (optional) Additional data to send, including limit,
     *      select_expression, consistent_read, etc...
     *
     * @return SelectIterator
     */
    public static function factory(SimpleDbClient $client, \SimpleXMLElement $selectResult, array $data = array())
    {
        $result = self::processSelectResult($selectResult);
        
        return new self($client, array_merge($data, array(
            'resources' => $result['resources'],
            'next_token' => $result['next_token'],
            'limit' => isset($data['limit']) ? $data['limit'] : -1
        )));
    }

    /**
     * Process a SelectResult
     *
     * @param \SimpleXMLElement $selectResult
     *
     * @return array
     */
    public static function processSelectResult(\SimpleXMLElement $selectResult)
    {
        $resources = array();

        foreach ($selectResult->SelectResult->Item as $item) {

            $data = array(
                'name' => (string)$item->Name,
                'attributes' => array()
            );

            foreach ($item->Attribute as $node) {
                $attribute = (string)$node->Name;
                $value = (string)$node->Value;
                if (!array_key_exists($attribute, $data['attributes'])) {
                    $data['attributes'][$attribute] = $value;
                } else if (!is_array($data['attributes'][$attribute])) {
                    $data['attributes'][$attribute] = array($data['attributes'][$attribute], $value);
                } else {
                    $data['attributes'][$attribute][] = $value;
                }
            }

            $resources[] = $data;
        }

        return array(
            'resources' => $resources,
            'next_token' => (string)$selectResult->SelectResult->NextToken
        );
    }

    /**
     * Send a request to retrieve the next page of results.
     *
     * @return void
     */
    protected function sendRequest()
    {
        // Issue a select command
        $command = new Select();
        $command->setSelectExpression($this->data['select_expression'])
            ->setNextToken($this->nextToken)
            ->setConsistentRead($this->data['consistent_read'])
            ->setXmlResponseOnly(true)
            ->setClient($this->client)
            ->execute();

        $result = self::processSelectResult($command->getResult());
        $this->resourceList = $result['resources'];
        $this->nextToken = $result['next_token'];
        $this->retrievedCount += count($this->resourceList);
        $this->currentIndex = 0;
    }

    /**
     * {@inheritdoc}
     */
    public function current()
    {
        return $this->current['attributes'];
    }

    /**
     * Get the select expression used with the iterator
     *
     * @return string
     */
    public function getSelectExpression()
    {
        return $this->data['select_expression'];
    }

    /**
     * Get whether or not the consistent read parameter is being used
     *
     * @return bool
     */
    public function isConsistentRead()
    {
        return $this->data['consistent_read'] ?: false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function key()
    {
        return $this->current['name'];
    }
}