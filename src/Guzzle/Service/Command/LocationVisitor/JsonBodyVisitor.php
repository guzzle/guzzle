<?php

namespace Guzzle\Service\Command\LocationVisitor;

use Guzzle\Http\Message\RequestInterface;
use Guzzle\Service\Command\CommandInterface;

/**
 * Visitor used to apply a parameter to an array that will be serialized as a
 * top level key-value pair in a JSON body
 */
class JsonBodyVisitor extends AbstractVisitor
{
    /**
     * @var bool Whether or not to add a Content-Type header when JSON is found
     */
    protected $jsonContentType = null;

    /**
     * @var \SplObjectStorage Data object for persisting JSON data
     */
    protected $data;

    /**
     * This visitor uses an {@see \SplObjectStorage} to associate JSON data with commands
     */
    public function __construct()
    {
        $this->data = new \SplObjectStorage();
    }

    /**
     * Set the Content-Type header to add to the request if JSON is added to
     * the body. This visitor does not add a Content-Type header unless you
     * specify one here.
     *
     * @param string $header Header to set when JSON is added (e.g. application/json)
     *
     * @return self
     */
    public function setContentTypeHeader($header = 'application/json')
    {
        $this->jsonContentType = $header;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function visit(CommandInterface $command, RequestInterface $request, $key, $value)
    {
        if (isset($this->data[$command])) {
            $json = $this->data[$command];
            $json[$key] = $value;
            $this->data[$command] = $json;
        } else {
            $this->data[$command] = array($key => $value);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function after(CommandInterface $command, RequestInterface $request)
    {
        if (isset($this->data[$command])) {
            $json = $this->data[$command];
            unset($this->data[$command]);
            $request->setBody(json_encode($json))->removeHeader('Expect');
            if ($this->jsonContentType) {
                $request->setHeader('Content-Type', $this->jsonContentType);
            }
        }
    }
}
