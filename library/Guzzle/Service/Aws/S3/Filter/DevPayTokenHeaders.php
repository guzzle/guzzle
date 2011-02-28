<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\S3\Filter;

use Guzzle\Common\Filter\AbstractFilter;
use Guzzle\Http\Message\RequestInterface;

/**
 * Add Amazon DevPay tokens to the Amazon S3 request
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DevPayTokenHeaders extends AbstractFilter
{
    /**
     * Add Amazon DevPay security tokens to the request headers
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

        if ($this->get('product_token') && $this->get('user_token')) {
            $command->setHeader(
                'x-amz-security-token',
                $this->get('user_token') . ', ' . $this->get('product_token')
            );
        }

        return true;
    }
}