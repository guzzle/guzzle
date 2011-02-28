<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Sqs\Command;

/**
 * The AddPermission action adds a permission to a queue for a specific
 * principal. This allows for sharing access to the queue.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 *
 * @guzzle queue_url required="true" doc="URL of the queue"
 * @guzzle label required="true" doc="The identfication of the permission you want to add."
 * @guzzle permissions required="true" doc="Array of arrays of permissions"
 */
class AddPermission extends AbstractQueueUrlCommand
{
    protected $action = 'AddPermission';

    /**
     * {@inheritdoc{
     */
    protected function init()
    {
        $this->set('permissions', array());
    }

    /**
     * {@inheritdoc}
     */
    protected function build()
    {
        parent::build();

        $qs = $this->request->getQuery();
        $qs->set('Label', $this->get('label'));

        foreach ($this->get('permissions') as $i => $permission) {
            $qs->set('AWSAccountId.' . ($i + 1), $permission['account']);
            $qs->set('ActionName.' . ($i + 1), $permission['action']);
        }
    }

    /**
     * Set the identfication of the permission you want to add.
     *
     * @param string $label Label to add
     *
     * @return AddPermission
     */
    public function setLabel($label)
    {
        return $this->set('label', $label);
    }

    /**
     * Add a permission to the request
     *
     * @param string $account AWS account ID
     * @param string $action Action to allow for this account
     *
     * @return AddPermission
     */
    public function addPermission($account, $action)
    {
        return $this->add('permissions', array(
            'account' => $account,
            'action' => $action
        ));
    }
}