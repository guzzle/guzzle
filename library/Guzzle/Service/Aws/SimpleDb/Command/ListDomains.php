<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\HttpException;
use Guzzle\Service\Aws\SimpleDb\SimpleDbException;

/**
 * List the domains owned by your account.
 *
 * By default, this command will return all domains in your account.  If you
 * own more domains than Amazon SimpleDB returns, then subsequent requests will
 * be issued to retrieve additional results.  To disable this behavior, call
 * setIterate(false).
 *
 * @link http://docs.amazonwebservices.com/AmazonSimpleDB/latest/DeveloperGuide/index.html?SDB_API_ListDomains.html
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle max_domains doc="Maximum number of domains to retrieve"
 * @guzzle iterate default="true" doc="Whether or not subsequent requests will be issued to retrieve all domains"
 * @guzzle next_token doc="NextToken of the request"
 */
class ListDomains extends AbstractSimpleDbCommand
{
    /**
     * {@inheritdoc}
     */
    protected $canBatch = false;

    /**
     * @var array An array of domains that have been returned
     */
    protected $domains = array();

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest(RequestInterface::GET);
        $this->request->getQuery()->set('Action', 'ListDomains');

        if ($this->get('max_domains')) {
            $this->request->getQuery()->set('MaxNumberOfDomains', $this->get('max_domains'));
        }

        if ($this->get('next_token')) {
            $this->request->getQuery()->set('NextToken', $this->get('next_token'));
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        $xml = new \SimpleXMLElement($this->getResponse()->getBody(true));
        
        while ($xml instanceof \SimpleXMLElement) {

            // Add the results to the list of domains
            foreach ($xml->ListDomainsResult->DomainName as $domain) {
                $this->domains[] = (string)$domain;
            }

            $nextToken = (string)$xml->ListDomainsResult->NextToken;
            
            // If the command has been instructed to iterate over responses,
            // do so now by issuing subsequent requests until no NextToken is
            // returned in the responses
            if (!$this->get('iterate') || !$nextToken) {
                break;
            } else {

                $command = new self();
                $command->setIterate(false)
                        ->setMaxDomains($this->get('max_domains', 100))
                        ->setNextToken($nextToken);

                try {
                    $this->getClient()->execute($command);
                    $xml = new \SimpleXMLElement($command->getResponse()->getBody(true));
                    unset($command);
                } catch (HttpException $e) {
                    // @codeCoverageIgnoreStart
                    $ex = new SimpleDbException($e->getMessage());
                    throw $ex;
                    // @codeCoverageIgnoreEnd
                }
            }
        }

        $this->result = array_unique($this->domains);
    }

    /**
     * {@inheritdoc}
     * 
     * @return array Returns an array of domain names
     */
    public function getResult()
    {
        if ($this->result) {
            return $this->result;
        }

        $this->process();

        return $this->result;
    }

    /**
     * Set to TRUE or FALSE to issue subsequent requests to retrieve additional
     * domain results
     *
     * @param bool $iterate
     * 
     * @return ListDomains
     */
    public function setIterate($iterate)
    {
        return $this->set('iterate', $iterate);
    }

    /**
     * Set the maximum number of domains to retrieve in a single request
     *
     * @param integer $maxDomains
     * 
     * @return ListDomains
     */
    public function setMaxDomains($maxDomains)
    {
        return $this->set('max_domains', $maxDomains);
    }

    /**
     * Set the next token
     *
     * @param integer $nextToken
     * 
     * @return ListDomains
     */
    public function setNextToken($nextToken)
    {
        return $this->set('next_token', $nextToken);
    }
}