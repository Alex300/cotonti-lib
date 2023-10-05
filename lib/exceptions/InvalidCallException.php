<?php

declare(strict_types=1);

namespace exceptions;

use BadMethodCallException;

/**
 * InvalidCallException represents an exception caused by calling a method in a wrong way.
 */
class InvalidCallException extends BadMethodCallException
{
    /**
     * @return string the user-friendly name of this exception
     */
    public function getName(): string
    {
        return 'Invalid Call';
    }
}
