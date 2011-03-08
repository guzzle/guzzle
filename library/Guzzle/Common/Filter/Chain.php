<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Common\Filter;

use \Countable;
use Guzzle\Common\Filter\FilterInterface;

/**
 * Iterate over a chain of {@see FilterInterface}s.
 *
 * Provides a filter chain that enables intercepting filters to modify an
 * object's behavior.  Each of the filters added to the chain are iterated and
 * run by the chain.  Filters are self-contained components without any direct
 * dependency on other filters.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class Chain implements Countable
{
    /**
     * @var array Chain of {@see FilterInterface} objects.
     */
    protected $filters = array();

    /**
     * Set to TRUE to discontinue the execution of the chain if a filter in the
     * chain is able to handle the command
     *
     * @var bool
     */
    protected $breakOnProcess = false;

    /**
     * Constructor
     *
     * @param array $filters (optional) Array of {@see FilterInterface} objects
     * @param bool $breakOnProcess (optional) Set to TRUE to break the chain
     *      when a filter successfully processes a command
     *
     * @throws InvalidArgumentException if the passed filters do not implement
     *      {@see FilterInterface}
     */
    public function __construct(array $filters = null, $breakOnProcess = false)
    {
        if ($filters) {
            foreach ($filters as $filter) {
                $this->addFilter($filter);
            }
        }
        $this->breakOnProcess = $breakOnProcess;
    }

    /**
     * Add an {@see FilterInterface} filter to the chain.
     *
     * @param FilterInterface $filter Filter to add to the chain
     *
     * @return Chain
     */
    public function addFilter(FilterInterface $filter)
    {
        $this->filters[] = $filter;

        return $this;
    }

    /**
     * Get the total number of filters in the chain
     *
     * @return int
     */
    public function count()
    {
        return count($this->filters);
    }

    /**
     * Get whether or not the chain will break when a filter successfully
     * processed a command
     *
     * @return bool
     */
    public function getBreakOnProcess()
    {
        return $this->breakOnProcess;
    }

    /**
     * Get all filters in the chain
     *
     * @param string $name (optional) Class name of the filters to retrieve
     *
     * @return array Returns an array of matching filters (may be empty)
     */
    public function getFilters($name = false)
    {
        if (!$name) {
            return $this->filters;
        }

        $results = array();
        foreach ($this->filters as $filter) {
            if ($filter instanceof $name) {
                $results[] = $filter;
            }
        }

        return $results;
    }

    /**
     * Check if the chain contains a specific filter
     *
     * @param FilterInterface|string $filter Check for the presence of an
     *      instance of $filter if $filter is a string or is the chain contains
     *      the filter if $filter is an instance of FilterInterface
     *
     * @return bool Returns TRUE if the chain contains the filter, FALSE if not
     */
    public function hasFilter($filter)
    {
        foreach ($this->filters as $index => $item) {
            if ((is_string($filter)  && $item instanceOf $filter)
                || $filter === $item) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add an {@see FilterInterface} filter to the beginning of the chain.
     *
     * @param FilterInterface $filter Filter to prepend to the chain
     *
     * @return Chain
     */
    public function prependFilter(FilterInterface $filter)
    {
        array_unshift($this->filters, $filter);

        return $this;
    }

    /**
     * Process a command using a chain of {@see FilterInterface}.
     *
     * @param mixed $command Object to process.
     *
     * @return mixed|array Returns the return value of a filter if break on
     *      process is true or the array of filter return values
     */
    public function process($command)
    {
        $results = array();

        foreach ($this->filters as $filter) {
            $result = $filter->process($command);
            if ($result && $this->breakOnProcess) {
                return $result;
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Remove a {@see FilterInterface} filter from the chain
     *
     * @param FilterInterface|string $filter The filter to remove from the
     *      chain.  Pass a string to remove all instances of a class or a
     *      concrete {@see FilterInterface} filter to remove a specific filter
     *
     * @return FilterInterface|bool Returns the removed filter or
     *      FALSE if the filter was not found
     */
    public function removeFilter($filter)
    {
        $found = false;
        foreach ($this->filters as $index => $item) {
            if ((is_string($filter)  && $filter instanceOf $item)
                || $filter === $item) {
                $found = $this->filters[$index];
                unset($this->filters[$index]);
            }
        }
        // Numerically reindex the array
        $this->filters = array_values($this->filters);

        return $found;
    }

    /**
     * Remove all {@see FilterInterface}s from the chain.
     *
     * @return array Returns an array of all removed filters
     */
    public function removeAllFilters()
    {
        $filters = $this->filters;
        $this->filters = array();

        return $filters;
    }

    /**
     * Specify the behavior of the chain when a filter processed the command
     *
     * @param bool $breakOnProcess Set to TRUE to break the chain when a filter
     *      is able to process the command.  Setting to FALSE will allow all
     *      filters to handle the command regardless of the result of other
     *      filters.
     *
     * @return Chain
     */
    public function setBreakOnProcess($breakOnProcess)
    {
        $this->breakOnProcess = (bool) $breakOnProcess;
        
        return $this;
    }

    /**
     * Run a command through the filters for validation.  If any filters return
     * true then this method returns TRUE.
     *
     * @param mixed $command Object to process.
     *
     * @return bool
     */
    public function oneTrue($command)
    {
        return count(array_filter($this->filters, function($filter) use ($command) {
            return $filter->process($command);
        })) > 0;
    }

    /**
     * Run a command through the filters for validation and return TRUE if all
     * filters return NULL or FALSE.  If any filters return TRUE then this
     * method returns FALSE.
     *
     * @param mixed $command Object to process.
     *
     * @return bool
     */
    public function noneTrue($command)
    {
        return count(array_filter($this->filters, function($filter) use ($command) {
            return $filter->process($command);
        })) == 0;
    }

    /**
     * Run a command through the filters for validation and return TRUE if all
     * filters do not return FALSE
     *
     * @param mixed $command Object to process.
     *
     * @return bool
     */
    public function allTrue($command)
    {
        return count(array_filter($this->filters, function($filter) use ($command) {
            return $filter->process($command);
        })) == count($this->filters);
    }
}