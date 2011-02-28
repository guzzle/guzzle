<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Command;

use Guzzle\Service\Command\AbstractCommand;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Common\Inflector;
use Guzzle\Service\Aws\Mws\Model\CsvReport;

/**
 * MWS command base class
 *
 * All MWS commands inherit this default functionality.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class AbstractMwsCommand extends AbstractCommand
{
    /**
     * @var string MWS operation name
     */
    protected $action;

    /**
     * @var string HTTP request method
     */
    protected $requestMethod = RequestInterface::GET;

    /**
     * Prepare command before execution
     */
    protected function build()
    {
        if (!$this->request) {
            $this->request = $this->client->getRequest($this->requestMethod);
        }

        $this->request->getQuery()
            ->set('Action', $this->action);

        // Set authorization fields
        $config = $this->getClient()->getConfig();
        $this->request->getQuery()
            ->set('AWSAccessKeyId', $config['access_key_id'])
            ->set('Marketplace', $config['marketplace_id'])
            ->set('Merchant', $config['merchant_id']);

        // Add any additional method params
        foreach($this->data as $param => $value) {
            if ($param == 'headers') {
                continue;
            }
            $param = ucfirst(Inflector::camel($param));
            if (is_array($value)) {
                // It's an array, convert to amazon array naming convention
                foreach($value as $listName => $listValues) {
                    foreach($listValues as $i => $listValue) {
                        $this->request->getQuery()->set($param . '.' . $listName . '.' . ($i + 1), $listValue);
                    }
                }
                $this->request->getQuery()->remove($param);
            } else if ($value instanceof \DateTime) {
                // It's a date, format as ISO 8601 string
                $this->request->getQuery()->set($param, $value->format('c'));
            } else if (is_bool($value)) {
                // It's a bool, convert to string
                $this->request->getQuery()->set($param, $value ? 'true' : 'false');
            } else {
                // It's a scalar
                $this->request->getQuery()->set($param, $value);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        parent::process();
        
        if ($this->result instanceof \SimpleXMLElement) {
            // Get result object from XML response
            $node = $this->action . 'Result';
            $this->result = $this->result->{$node};
        } else if ($this->result->getContentType() == 'application/octet-stream') {
            // Get CSV data array
            $this->result = new CsvReport($this->getResponse()->getBody(true));
        }
    }
}