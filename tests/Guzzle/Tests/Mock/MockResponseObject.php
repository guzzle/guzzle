<?php


namespace Guzzle\Tests\Mock;


class MockResponseObject {

    protected $salutation;
    protected $subject;

    function __construct($salutation, $subject)
    {
        $this->salutation = $salutation;
        $this->subject = $subject;
    }

    function getSalutation()
    {
        return $this->salutation;
    }

    function getSubject()
    {
        return $this->subject;
    }
}



?>
