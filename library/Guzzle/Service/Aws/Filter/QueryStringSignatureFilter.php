<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Filter;

use Guzzle\Common\Filter\AbstractFilter;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Aws\S3\S3Client;

/**
 * Add a query string signature to a request
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class QueryStringSignatureFilter extends AbstractFilter
{
    /**
     * Add a signature to the outbound request
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    protected function filterCommand($command)
    {
        // @codeCoverageIgnoreStart
        if (!($command instanceof RequestInterface)) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        $signature = $this->get('signature');
        // @codeCoverageIgnoreStart
        if (!$signature) {
            return false;
        }
        // @codeCoverageIgnoreEnd

        $qs = $command->getQuery();

        // Create a string that needs to be signed using the request settings
        $strToSign = $signature->calculateStringToSign($qs->getAll(), array(
            'endpoint' => $command->getUrl(),
            'method' => $command->getMethod()
        ));

        // Add the signature to the query string of the request
        $qs->set('Signature', $signature->signString($strToSign));

        return true;
    }
}