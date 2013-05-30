<?php

namespace Guzzle\Service\Command;

use Guzzle\Common\ToArrayInterface;

/**
 * A command object that contains parameters that can be modified and accessed like an array and turned into an array
 */
interface ArrayCommandInterface extends CommandInterface, \ArrayAccess, ToArrayInterface {}
