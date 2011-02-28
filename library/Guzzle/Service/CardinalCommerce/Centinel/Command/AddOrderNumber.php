<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\CardinalCommerce\Centinel\Command;

/**
 * This message instructs Checkout By Amazon to associate a merchant-assigned
 * order number with an order. This command does not impact the order's
 * fulfillment state.
 *
 * @author Michael Dowling <michael@shoebacca.com>
 *
 * @guzzle msg_type static="cmpi_add_order_number"
 * @guzzle transaction_type required="true"
 * @guzzle order_id required="true"
 * @guzzle order_number required="true"
 */
class AddOrderNumber extends Txn
{
    /**
     * Centinel generated transaction identifier.
     *
     * @param string $value Value to set
     *
     * @return AddOrderNumber
     */
    public function setOrderId($value)
    {
        return $this->set('order_id', $value);
    }

    /**
     * Set the order number that you have assigned to an order.
     *
     * @param string $value Value to set
     *
     * @return AddOrderNumber
     */
    public function setOrderNumber($value)
    {
        return $this->set('order_number', $value);
    }
}