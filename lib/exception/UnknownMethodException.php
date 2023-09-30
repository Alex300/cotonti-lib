<?php

declare(strict_types=1);

namespace exception;

use BadMethodCallException;

/**
 * UnknownMethodException represents an exception caused by accessing an unknown object method
 */
class UnknownMethodException extends BadMethodCallException
{
    /**
     * @return string the user-friendly name of this exception
     */
    public function getName(): string
    {
        return 'Unknown Method';
    }
}
