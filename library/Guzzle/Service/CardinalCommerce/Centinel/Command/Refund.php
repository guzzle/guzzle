<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\CardinalCommerce\Centinel\Command;

/**
 * The Refund message (cmpi_refund) is responsible for crediting the consumer
 * some portion of the original payment amount. Multiple refunds can be 
 * processed against the transaction.
 *
 * @author Michael Dowling <michael@shoebacca.com>
 *
 * @guzzle msg_type static="cmpi_refund"
 * @guzzle transaction_type required="true"
 * @guzzle currency_code required="true" default="840"
 */
class Refund extends Txn
{
    /**
     * @var int Number of items in the request
     */
    protected $itemCount = 0;

    /**
     * Centinel generated transaction identifier.
     *
     * @param string $value Value to set
     *
     * @return Refund
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
     * @return Refund
     */
    public function setOrderDescription($value)
    {
        return $this->set('order_description', $value);
    }

    /**
     * Brief reason of refund
     *
     * @param string $value Value to set
     *
     * @return Refund
     */
    public function setReason($value)
    {
        return $this->set('reason', $value);
    }

    /**
     * Unformatted Total sale amount without any decimalization.  For example,
     * $100.00 = 10000, $123.67 = 12367, $.99 = 99
     *
     * @param string $value Value to set
     *
     * @return Refund
     */
    public function setAmount($value)
    {
        return $this->set('amount', $this->convertCurrency($value));
    }

    /**
     * 3 digit numeric, ISO 4217 currency code for the sale amount.
     *  For example: USD - 840, EUR - 978, JPY - 392, CAD - 124, GBP - 826
     *
     * @param string $value Value to set
     *
     * @return Refund
     */
    public function setCurrencyCode($value)
    {
        return $this->set('currency_code', $value);
    }

    /**
     * Add an item to the refund request
     *
     * @param string $sku SKU of the item to refund
     * @param string $price Price of the item to refund
     *
     * @return Refund
     */
    public function addProduct($sku, $price)
    {
        $i = ++$this->itemCount;
        $this->set("Item_SKU_{$i}", $sku)
             ->set("Item_Price_{$i}", $this->convertCurrency($price));

        return $this;
    }
}