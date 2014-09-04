<?php
namespace GuzzleHttp\Event;

/**
 * Trait that provides methods for extract event listeners specified in an array
 * and attaching them to an emitter owned by the object or one of its direct
 * dependencies.
 */
trait ListenerAttacherTrait
{
    /**
     * Attaches event listeners and properly sets their priorities and whether
     * or not they are are only executed once.
     *
     * @param HasEmitterInterface $object    Object that has the event emitter.
     * @param array               $listeners Array of hashes representing event
     *                                       event listeners. Each item contains
     *                                       "name", "fn", "priority", & "once".
     */
    private function attachListeners(HasEmitterInterface $object, array $listeners)
    {
        $emitter = $object->getEmitter();
        foreach ($listeners as $el) {
            if ($el['once']) {
                $emitter->once($el['name'], $el['fn'], $el['priority']);
            } else {
                $emitter->on($el['name'], $el['fn'], $el['priority']);
            }
        }
    }

    /**
     * Extracts the allowed events from the provided array, and ignores anything
     * else in the array. The event listener must be specified as a callable or
     * as an array of event listener data ("name", "fn", "priority", "once").
     *
     * @param array $source Array containing callables or hashes of data to be
     *                      prepared as event listeners.
     * @param array $events Names of events to look for in the provided $source
     *                      array. Other keys are ignored.
     * @return array
     */
    private function prepareListeners(array $source, array $events)
    {
        $listeners = [];
        foreach ($events as $name) {
            if (isset($source[$name])) {
                $this->buildListener($name, $source[$name], $listeners);
            }
        }

        return $listeners;
    }

    /**
     * Creates a complete event listener definition from the provided array of
     * listener data. Also works recursively if more than one listeners are
     * contained in the provided array.
     *
     * @param string         $name      Name of the event the listener is for.
     * @param array|callable $data      Event listener data to prepare.
     * @param array          $listeners Array of listeners, passed by reference.
     *
     * @throws \InvalidArgumentException if the event data is malformed.
     */
    private function buildListener($name, $data, &$listeners)
    {
        static $defaults = ['priority' => 0, 'once' => false];

        // If a callable is provided, normalize it to the array format.
        if (is_callable($data)) {
            $data = ['fn' => $data];
        }

        // Prepare the listener and add it to the array, recursively.
        if (isset($data['fn'])) {
            $data['name'] = $name;
            $listeners[] = $data + $defaults;
        } elseif (is_array($data)) {
            foreach ($data as $listenerData) {
                $this->buildListener($name, $listenerData, $listeners);
            }
        } else {
            throw new \InvalidArgumentException('Each event listener must be a '
                . 'callable or an associative array containing a "fn" key.');
        }
    }
}
