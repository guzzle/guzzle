<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\CardinalCommerce\Centinel\Command;

/**
 * This message is used to request the status of a transaction referenced by the
 * NotificationId value. Centinel will notify the merchant by posting a request
 * to the notification Location configured within the merchant's Centinel
 * profile. The request includes the NotificationId value for use on the 
 * cmpi_payment_status message.
 *
 * @author Michael Dowling <michael@shoebacca.com>
 *
 * @guzzle msg_type static="cmpi_payment_status"
 * @guzzle notification_id required="true"
 */
class PaymentStatus extends Txn
{
    /**
     * Set the Notification Id. This value is required to be passed on the
     * cmpi_payment_status message to identify the corresponding transaction.
     * This value is provided through the HTTP POST Notification sent to the
     * Merchant.
     *
     * @param string $value Value to set
     *
     * @return PaymentStatus
     */
    public function setNotificationId($value)
    {
        return $this->set('notification_id', $value);
    }
}