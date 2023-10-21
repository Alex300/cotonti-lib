<?php

declare(strict_types=1);

namespace filesystem\exceptions;

use Exception;
use files\exceptions\FilesExceptionInterface;

class UnknownFilesystemException extends Exception implements FilesExceptionInterface
{

}