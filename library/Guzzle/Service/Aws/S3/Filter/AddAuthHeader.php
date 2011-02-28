<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Filter;

use Guzzle\Service\Aws\S3\S3Client;
use Guzzle\Common\Filter\AbstractFilter;
use Guzzle\Http\Message\RequestInterface;

/**
 * Add an authorization header to a request
 *
 * This filter is required for sending authenticated requests to Amazon S3.
 * Requests will be sent anonomously when this filter is not present in the
 * prepare chain of a RequestInterface object.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class AddAuthHeader extends AbstractFilter
{
    /**
     * Add an Amazon S3 authorization header to the outbound request
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
        if (!$signature) {
            return false;
        }

        $path = ($command->getResourceUri()) ? $command->getResourceUri() : '';

        $headers = array_change_key_case($command->getHeaders()->getAll());
        if (!array_key_exists('Content-Length', $headers)) {
            $headers['Content-Type'] = $command->getHeader('Content-Type');
        }

        $canonicalizedString = $signature->createCanonicalizedString($headers, $path, $command->getMethod());
        
        $command->setHeader(
            'Authorization',
            'AWS ' . $signature->getAccessKeyId(). ':' . $signature->signString($canonicalizedString)
        );

        return true;
    }
}