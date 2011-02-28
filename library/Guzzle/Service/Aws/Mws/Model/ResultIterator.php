<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Aws\Mws\Model;

use Guzzle\Service\Aws\Mws\MwsClient;
use Guzzle\Service\Aws\Mws\Command\AbstractMwsCommand;
use Guzzle\Service\Aws\Mws\Command\IterableInterface;
use Guzzle\Common\Inflector;

/**
 * Result iterator class
 *
 * Automatically iterates over multiple pages of results,
 * requesting additional pages as needed.
 *
 * @author Harold Asbridge <harold@shoebacca.com>
 */
class ResultIterator implements \Iterator
{
    /**
     * @var MwsClient
     */
    protected $client;

    /**
     * @var AbstractMwsCommand
     */
    protected $command;

    /**
     * @var array results
     */
    protected $pages = array();

    /**
     * @var int current page number
     */
    protected $currentPage = 0;

    /**
     * @var int current position (on the current page)
     */
    protected $currentPos = 0;

    /**
     * @var string next command name (<command>_by_next_token)
     */
    protected $nextCommandName;

    /**
     * @var bool whether or not another page of results exists
     */
    protected $hasNext = true;

    /**
     * Initialize iterator
     *
     * @param MwsClient $client
     * @param AbstractMwsCommand $command
     */
    public function __construct(MwsClient $client, AbstractMwsCommand $command)
    {
        if (false === ($command instanceof IterableInterface)) {
            throw new \InvalidArgumentException('Command must be iterable');
        }
        $this->client = $client;
        $this->command = $command;

        // Calculate next command name
        $className = get_class($this->command);
        preg_match('#^.*\\\(.*)$#', $className, $matches);
        $className = Inflector::snake($matches[1]);
        $this->nextCommandName = $className . '_by_next_token';
    }

    /**
     * Load a page of results
     *
     * @return bool
     */
    protected function loadResults()
    {
        if (!$this->hasNext) {
            return false;
        }

        //print 'Getting Page ' . $this->currentPage . PHP_EOL;

        $result = $this->client->execute($this->command);
        $this->pages[$this->currentPage] = $this->parseResult($result);
        $this->hasNext = ($result->HasNext == 'true');

        $this->command = $this->client->getCommand($this->nextCommandName)
            ->setNextToken((string)$result->NextToken);

        return true;
    }

    /**
     * Parse result, find record array in it
     *
     * @param \SimpleXMLElement $result
     *
     * @return array
     */
    protected function parseResult($result)
    {
        // Get all properties on object
        $props = array_keys(get_object_vars($result));
        
        // Filter out HasNext and NextToken, whatever is left is our array of results
        $props = array_filter($props, function($val){
            return !in_array($val, array('HasNext', 'NextToken'));
        });

        // Should be only one property left, and it should be the result array
        // @codeCoverageIgnoreStart
        if (count($props) > 1) {
            throw new \UnexpectedValueException('Unable to parse response');
        }
        // @codeCoverageIgnoreEnd

        // Get property name
        $props = array_values($props);
        $resultProperty = $props[0];

        // Copy to array
        $out = array();
        foreach($result->{$resultProperty} as $row) {
            $out[] = $row;
        }
        unset($result);
        
        return $out;
    }

    /**
     * Get current result element
     *
     * @return mixed
     */
    public function current()
    {
        return $this->pages[$this->currentPage][$this->currentPos];
    }

    /**
     * Seek to next result element
     */
    public function next()
    {
        $this->currentPos++;
        if (!isset($this->pages[$this->currentPage][$this->currentPos])) {
            $this->currentPage++;
            $this->currentPos = 0;
            usleep(250000); // throttle requests
            $this->loadResults();
        }
    }

    /**
     * Rewind array to beginning
     */
    public function rewind()
    {
        $this->currentPage = 0;
        $this->currentPos = 0;

        // Init first page of results if it's not already loaded
        if (empty($this->pages[0])) {
            $this->loadResults();
        }
    }

    /**
     * Test if current position is valid
     *
     * @return bool
     */
    public function valid()
    {
        return isset($this->pages[$this->currentPage][$this->currentPos]);
    }

    /**
     * Get current array key
     *
     * @return string
     */
    public function key()
    {
        return $this->currentPage . '_' . $this->currentPos;
    }

}