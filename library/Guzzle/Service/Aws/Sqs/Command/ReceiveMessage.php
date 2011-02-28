<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs\Command;

use Guzzle\Common\Inflector;

/**
 * Receive messages from a queue
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle queue_url required="true" doc="URL of the queue"
 * @guzzle visibility_timeout doc="The duration (in seconds) that the received messages are hidden from subsequent retrieve requests after being retrieved by a ReceiveMessage request."
 * @guzzle max_messages doc="The maximum number of messages to retrieve per request"
 */
class ReceiveMessage extends AbstractQueueUrlCommand
{
    const ALL = 'All';
    const SENDER_ID = 'SenderId';
    const SENT_TIMESTAMP = 'SentTimestamp';
    const APPROXIMATE_RECEIVE_COUNT = 'ApproximateReceiveCount';
    const APPROXIMATE_FIRST_RECEIVE_TIMESTAMP = 'ApproximateFirstReceiveTimestamp';

    protected $action = 'ReceiveMessage';

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        parent::build();

        if ($this->get('visibility_timeout')) {
            $this->request->getQuery()->set('VisibilityTimeout', $this->get('visibility_timeout'));
        }

        if ($this->get('max_messages')) {
            $this->request->getQuery()->set('MaxNumberOfMessages', $this->get('max_messages'));
        }

        if ($this->get('attribute')) {
            foreach ((array)$this->get('attribute') as $i => $attribute) {
                $this->request->getQuery()->set('AttributeName.' . ($i + 1), trim($attribute));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process()
    {
        parent::process();
        
        $this->result = array();

        foreach ($this->xmlResult->ReceiveMessageResult->Message as $message) {
            $row = array(
                'message_id' => trim((string)$message->MessageId),
                'receipt_handle' => str_replace(' ', '', trim((string)$message->ReceiptHandle)),
                'md5_of_body' => trim((string)$message->MD5OfBody),
                'body' => trim((string)$message->Body)
            );

            foreach ($message->Attribute as $attribute) {
                $row[Inflector::snake(trim((string)$attribute->Name))] = trim((string)$attribute->Value);
            }

            $this->result[] = $row;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @return array Returns a normalized array of message information.  Messages
     *      are represented as an array of associative arrays.  Each associative
     *      array contains a snake_case form of the response data from SQS,
     *      including attributes that have been retrieved.
     *
     * <code>
     * array(
     *     array(
     *         'message_id' => '5fea7756-0ea4-451a-a703-a558b933e274',
     *         'receipt_handle' => "MbZj6wDWli+JvwwJaBV+3dcjk2YW2vA3+STFFljTM8tJJg6HRG6PYSasuWXPJB+Cw\nLj1FjgXUv1uSj1gUPAWV66FU/WeR4mq2OKpEGYWbnLmpRCJVAyeMjeU5ZBdtcQ+QE\nauMZc8ZRv37sIW2iJKq3M9MFx1YvV11A2x/KSbkJ0=",
     *         'md5_of_body' => 'fafb00f5732ab283681e124bf8747ed1',
     *         'body' => 'This is a test message',
     *         'sender_id' => '195004372649',
     *         'sent_timestamp' => '1238099229000',
     *         'approximate_receive_count' => '5',
     *         'approximate_first_receive_timestamp' => '1250700979248'
     *     ),
     *     array(
     *         'message_id' => '5fea7756-0ea4-451a-a703-a558b933e275',
     *         'receipt_handle' => "MbZj6wDWli+JvwwJaBV+3dcjk2YW2vA3+STFFljTM8tJJg6HRG6PYSasuWXPJB+Cw\nLj1FjgXUv1uSj1gUPAWV66FU/WeR4mq2OKpEGYWbnLmpRCJVAyeMjeU5ZBdtcQ+QE\nauMZc8ZRv37sIW2iJKq3M9MFx1YvV11A2x/KSbkJ1=",
     *         'md5_of_body' => 'fafb00f5732ab283681e124bf8747ed1',
     *         'body' => 'This is a test message',
     *         'sender_id' => '195004372649',
     *         'sent_timestamp' => '1238099229001',
     *         'approximate_receive_count' => '3',
     *         'approximate_first_receive_timestamp' => '1250700979228'
     *     )
     * );
     * </code>
     */
    public function getResult()
    {
        return parent::getResult();
    }

    /**
     * Add an attribute to the request
     *
     * @param string $attribute Attribute to add
     *
     * @return ReceiveMessage
     */
    public function addAttribute($attribute)
    {
        return $this->add('attribute', $attribute);
    }

    /**
     * Set the visibility timeout
     *
     * @param int $seconds The duration (in seconds) that the received messages
     *      are hidden from subsequent retrieve requests after being retrieved
     *      by a ReceiveMessage request.
     *
     * @return ReceiveMessage
     */
    public function setVisibilityTimeout($seconds)
    {
        return $this->set('visibility_timeout', $seconds);
    }

    /**
     * Set the maximum number of messages to retrieve per request
     *
     * @param int $maxMessages Max number of messages to retrieve (1 - 10)
     *
     * @return ReceiveMessage
     */
    public function setMaxMessages($maxMessages)
    {
        return $this->set('max_messages', max(1, min(10, $maxMessages)));
    }
}