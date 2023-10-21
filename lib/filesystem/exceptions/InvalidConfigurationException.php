<?php

declare(strict_types=1);

namespace filesystem\exceptions;

use Exception;
use filesystem\exceptions\FilesystemException;

class InvalidConfigurationException extends Exception implements FilesystemException
{

}