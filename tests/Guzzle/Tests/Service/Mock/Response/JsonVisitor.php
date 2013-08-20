<?php
namespace Guzzle\Tests\Service\Mock\Response;

use Guzzle\Service\Command\LocationVisitor\Response\JsonVisitor as BaseVisitor;

class JsonVisitor extends BaseVisitor
{
    /**
     * @param array $json
     */
    public function setJson(array $json)
    {
        $this->json = $json;
    }

    /**
     * @return array
     */
    public function getJson()
    {
        return $this->json;
    }
}
