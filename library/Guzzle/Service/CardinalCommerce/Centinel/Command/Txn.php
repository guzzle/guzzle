<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\CardinalCommerce\Centinel\Command;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\AbstractCommand;
use Guzzle\Http\QueryString;
use Guzzle\Service\CardinalCommerce\Centinel\CentinelErrorResponseException;
use Guzzle\Common\Collection;
use Guzzle\Common\Inflector;
use Guzzle\Common\Inspector;

/**
 * Default command object used for interacting with Cardinal Commerce
 *
 * @author Michael Dowling <michael@shoebacca.com>
 */
class Txn extends AbstractCommand
{
    /**
     * @var array Custom snake to Camel mappings
     */
    protected $mappings = array(
        'email_address' => 'EMail',
        'ip_address' => 'IPAddress',
        'par_es_payload' => 'PAResPayload'
    );

    /**
     * Build an XML message based on an array of settings
     *
     * @return string
     */
    protected function buildXml()
    {
        $queryString = '<CardinalMPI>';

        // Add default information
        $queryString .= '<Version>' . $this->client->getConfig('version') . '</Version>';
        $queryString .= '<ProcessorId>' . $this->client->getConfig('processor_id') . '</ProcessorId>';
        $queryString .= '<MerchantId>' . $this->client->getConfig('merchant_id') . '</MerchantId>';
        $queryString .= '<TransactionPwd>' . $this->client->getConfig('password') . '</TransactionPwd>';

        // Add custom fields
        $queryString .= '<Source>PHPTC</Source>';
        $queryString .= '<SourceVersion>' . $this->client->getConfig('version') . '</SourceVersion>';

        // Adding settings other than timeout specific settings
        foreach ($this->filter(function($key, $value) {
            return is_scalar($value) && strpos($key, 'timeout') === false && substr($key, -7) != 'headers';
        }, false) as $name => $value) {

            // if this key is in the mappings array, then use the custom mapping
            if (isset($this->mappings[$name])) {
                $name = $this->mappings[$name];
            } else {
                
                $ignore = false;

                // Be sure to skip keys that are already mixed case
                if (strtolower($name) === $name) {
                    // Most keys just use CamelCase
                    $name = ucfirst(Inflector::camel($name));
                }
            }

            $name = htmlentities($name);
            $queryString .= sprintf('<%s>%s</%s>', $name, htmlentities($value), $name);
        }

        // Get the timeout settings from the config
        $defaultTimeout = $this->get('timeout', '15000');
        $resolveTimeout = $this->get('resolve_timeout', $defaultTimeout);
        $sendTimeout = $this->get('send_timeout', $defaultTimeout);
        $receiveTimeout = $this->get('receive_timeout', $defaultTimeout);
        $connectTimeout = $this->get('connect_timeout', $defaultTimeout);

        $queryString .= '<ResolveTimeout>' . htmlentities($resolveTimeout) . '</ResolveTimeout>';
        $queryString .= '<SendTimeout>' . htmlentities($sendTimeout) . '</SendTimeout>';
        $queryString .= '<ReceiveTimeout>' . htmlentities($receiveTimeout) . '</ReceiveTimeout>';
        $queryString .= '<ConnectTimeout>' . htmlentities($connectTimeout) . '</ConnectTimeout>';
        $queryString .= '<TransactionUrl>' . htmlentities($this->get('url', $this->client->getBaseUrl())) . '</TransactionUrl>';
        $queryString .= '<MerchantSystemDate>' . htmlentities(gmdate('Y-m-d\TH:i:s\Z')) . '</MerchantSystemDate>';
        $queryString .= '</CardinalMPI>';

        return $queryString;
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest(RequestInterface::POST);
        
        $q = new QueryString(array(
            'cmpi_msg' => $this->buildXml()
        ));
        $q->setPrefix('');
        $this->request->addPostFields($q);

        return $this->request;
    }

    /**
     * Check for any errors in the 200 response
     *
     * {@inheritdoc}
     * @throws CentinelErrorResponseException if an error response was returned
     */
    protected function process()
    {
        parent::process();
        $this->result = new \SimpleXMLElement($this->request->getResponse()->getBody(true));

        if ((string)$this->result->ErrorDesc != '') {
            $e = new CentinelErrorResponseException((string)$this->result->ErrorNo . ': ' . (string)$this->result->ErrorDesc);
            $e->setRequest($this->request);
            $e->setResponse($this->request->getResponse());
            throw $e;
        }
    }

    /**
     * Format a currency value for Cardinal Commerce
     *
     * @param string|float|int $value Currency value
     *
     * @return int
     */
    public function convertCurrency($value)
    {
        return round(str_replace(array('$', ','), '', $value) * 100);
    }
    
    /**
     * Identifies the Transaction Type used for processing.
     *
     * C - Credit Card / Debit Card Authentication.
     * Ac - Checkout By Amazon
     *
     * @param string $value Value to set
     *
     * @return Lookup
     */
    public function setTransactionType($value)
    {
        return $this->set('transaction_type', $value);
    }
}