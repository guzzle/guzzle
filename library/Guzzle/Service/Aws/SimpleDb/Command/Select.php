<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\SimpleDb\Model\SelectIterator;

/**
 * Selects data from Amazon SimpleDB
 *
 * The Select operation returns a set of Attributes  for ItemNames that match
 * the select expression. Select is similar to the standard SQL SELECT statement
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_Select.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle select_expression required="true" doc="Select query expression"
 * @guzzle consistent_read doc="When set to true, ensures that the most recent data is returned"
 * @guzzle limit doc="Set a hard limit on the number of results to retrieve"
 * @guzzle next_token doc="Set the next token of the select query"
 */
class Select extends AbstractSimpleDbCommand
{
    /**
     * {@inheritdoc}
     */
    protected $action = 'Select';

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest(RequestInterface::GET);
        $this->request->getQuery()->set('Action', $this->action);
        $this->request->getQuery()->set('SelectExpression', $this->get('select_expression'));

        if ($this->get('next_token')) {
            $this->request->getQuery()->set('NextToken', $this->get('next_token'));
        }

        if ($this->get('consistent_read')) {
            $this->request->getQuery()->set('ConsistentRead', 'true');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $xml = new \SimpleXMLElement($this->getResponse()->getBody(true));
        if ($this->get('xml_only')) {
             $this->result = $xml;
        } else {
            $this->result = SelectIterator::factory($this->client, $xml, array(
                'consistent_read' => $this->get('consistent_read'),
                'select_expression' => $this->get('select_expression'),
                'limit' => $this->get('limit')
            ));
        }
    }

    /**
     * Returns a SelectIterator or a SimpleXMLElement
     *
     * @return SelectIterator|\SimpleXMLElement
     */
    public function getResult()
    {
        return parent::getResult();
    }
    
    /**
     * Set whether or not to use the ConsistentRead setting.
     * 
     * When set to true, ensures that the most recent data is returned.
     * 
     * @param bool $consistentRead Set to TRUE to use ConsistentRead
     * 
     * @return Select
     */
    public function setConsistentRead($consistentRead)
    {
        return $this->set('consistent_read', $consistentRead);
    }

    /**
     * Set the maximum number of items to retrieve from the domain when
     * iterating over results.  When the xml_only parameter is set, this
     * parameter is ignored
     *
     * @param integer $limit The maximum numbuer of items to retrieve with the
     *      iterator
     *
     * @return Select
     */
    public function setLimit($limit)
    {
        return $this->set('limit', $limit);
    }
    
    /**
     * Set the NextToken to use with the select expression
     * 
     * @param string $nextToken String that tells Amazon SimpleDB where to
     *      start the next list of ItemNames
     * 
     * @return Select
     */
    public function setNextToken($nextToken)
    {
        return $this->set('next_token', $nextToken);
    }

    /**
     * Get the supplied select expression
     *
     * @return string
     */
    public function getSelectExpression()
    {
        return $this->get('select_expression');
    }

    /**
     * Set the select expression
     *
     * @param string $expression The expression used to query the domain.
     *
     * @return Select
     */
    public function setSelectExpression($expression)
    {
        return $this->set('select_expression', $expression);
    }
    /**
     * Set to TRUE to format the response only as XML rather than create a new
     * SelectIterator
     *
     * @param bool $xmlResponseOnly
     * 
     * @return Select
     */
    public function setXmlResponseOnly($xmlResponseOnly)
    {
        return $this->set('xml_only', $xmlResponseOnly);
    }
}