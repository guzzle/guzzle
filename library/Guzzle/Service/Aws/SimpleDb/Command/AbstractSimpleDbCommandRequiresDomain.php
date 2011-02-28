<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\SimpleDb\Command;

use Guzzle\Http\Message\RequestInterface;

/**
 * Abstract class for SimpleDB commands that require a domain to be set
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AbstractSimpleDbCommandRequiresDomain extends AbstractSimpleDbCommand
{
    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        $this->request = $this->client->getRequest(RequestInterface::GET);
        $this->request->getQuery()
            ->set('Action', $this->action)
            ->set('DomainName', $this->get('domain'));
    }

    /**
     * Set the domain
     *
     * @param string $domain The domain to get metadata about
     *
     * @return AbstractSimpleDbCommandRequiresDomain
     */
    public function setDomain($key)
    {
        return $this->set('domain', $key);
    }
}