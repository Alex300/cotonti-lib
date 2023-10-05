<?php

declare(strict_types=1);

namespace filesystem\exceptions;

use InvalidArgumentException as BaseInvalidArgumentException;

class InvalidStreamProvided extends BaseInvalidArgumentException implements FilesystemException
{
}
