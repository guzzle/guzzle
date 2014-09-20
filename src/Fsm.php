<?php
namespace GuzzleHttp;

/**
 * Provides a basic finite state machine that transitions transaction objects
 * through state transitions provided in the constructor.
 */
class Fsm
{
    private $states;
    private $initialState;

    /**
     * The states array is an associative array of associative arrays
     * describing each state transition. Each key of the outer array is a state
     * name, and each value is an associative array that can contain the
     * following key value pairs:
     *
     * - transition: A callable that is invoked when entering the state. If
     *   the callable throws an exception then the FSM transitions to the
     *   error state. Otherwise, the FSM transitions to the success state.
     * - success: The state to transition to when no error is raised. If not
     *   present, then this is a terminal state.
     * - error: The state to transition to when an error is raised. If not
     *   present and an exception occurs, then the exception is thrown.
     *
     * @param string $initialState The initial state of the FSM
     * @param array  $states       Associative array of state transitions.
     */
    public function __construct($initialState, array $states)
    {
        $this->states = $states;
        $this->initialState = $initialState;
    }

    /**
     * Runs the state machine until a terminal state is entered or the
     * optionally supplied $finalState is entered.
     *
     * @param Transaction $trans      Transaction being transitioned.
     * @param string      $finalState The state to stop on. If unspecified,
     *                                runs until a terminal state is found.
     *
     * @throws \Exception if a terminal state throws an exception.
     */
    public function run(Transaction $trans, $finalState = null)
    {
        if (!$trans->state) {
            $trans->state = $this->initialState;
        }

        do {
            $terminal = $trans->state === $finalState;
            $state = $this->states[$trans->state];

            try {
                if (isset($state['transition'])) {
                    $state['transition']($trans);
                }
                // Break if the transition told us to bail, or if this is a
                // terminal state.
                if (!isset($state['success'])) {
                    break;
                }
                // Transition to the success state
                $trans->state = $state['success'];
            } catch (\Exception $e) {
                if (!isset($state['error'])) {
                    // No error state, so this is a terminal state and must
                    // throw an exception.
                    throw $e;
                }
                // Transition to the error state if possible.
                $trans->exception = $e;
                $trans->state = $state['error'];
            }

        } while (!$terminal);
    }
}
