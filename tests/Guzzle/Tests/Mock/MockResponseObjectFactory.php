<?php

namespace Guzzle\Tests\Mock;

use Guzzle\Service\Command\OperationCommand;
use Guzzle\Service\Command\ResponseClassInterface;

class MockResponseObjectFactory implements ResponseClassInterface {

    public static function fromCommand(OperationCommand $command)
    {
        $jsonData = $command->getRequest()->getResponse()->getBody(true);
        $data = json_decode($jsonData, true);
        return new MockResponseObject($data['salutation'], $data['subject']);
    }
}



?>
