<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\CardinalCommerce\Centinel\Command;

/**
 * The Void message (cmpi_void) is responsible for void an order. If an order
 * is charged it must be refunded before it can be voided.
 *
 * @author Michael Dowling <michael@shoebacca.com>
 *
 * @guzzle msg_type static="cmpi_void"
 * @guzzle transaction_type required="true"
 * @guzzle order_id required="true"
 * @guzzle order_description required="true"
 */
class Void extends Txn
{
    /**
     * Centinel generated transaction identifier.
     *
     * @param string $value Value to set
     *
     * @return Void
     */
    public function setOrderId($value)
    {
        return $this->set('order_id', $value);
    }

    /**
     * Brief description of items purchased.
     *
     * @param string $value Value to set
     *
     * @return Void
     */
    public function setOrderDescription($value)
    {
        return $this->set('order_description', $value);
    }

    /**
     * Brief reason of cancel, limited to 125 characters.
     *
     * @param string $value Value to set
     *
     * @return Void
     */
    public function setReason($value)
    {
        return $this->set('reason', $value);
    }
}