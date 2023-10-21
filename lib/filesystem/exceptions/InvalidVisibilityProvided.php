<?php

declare(strict_types=1);

namespace filesystem\exceptions;

use InvalidArgumentException;

use function var_export;

class InvalidVisibilityProvided extends InvalidArgumentException implements FilesystemException
{
    public static function withVisibility(string $visibility, string $expectedMessage): InvalidVisibilityProvided
    {
        $provided = var_export($visibility, true);
        $message = "Invalid visibility provided. Expected {$expectedMessage}, received {$provided}";

        throw new InvalidVisibilityProvided($message);
    }
}
