<?php

declare(strict_types=1);

namespace filesystem;

use Cot;
use DateTimeInterface;
use DirectoryIterator;
use filesystem\exceptions\InvalidStreamProvided;
use filesystem\exceptions\InvalidVisibilityProvided;
use filesystem\exceptions\SymbolicLinkEncountered;
use filesystem\exceptions\UnableToCopyFile;
use filesystem\exceptions\UnableToCreateDirectory;
use filesystem\exceptions\UnableToDeleteDirectory;
use filesystem\exceptions\UnableToDeleteFile;
use filesystem\exceptions\UnableToGenerateTemporaryUrl;
use filesystem\exceptions\UnableToListContents;
use filesystem\exceptions\UnableToMoveFile;
use filesystem\exceptions\UnableToProvideChecksum;
use filesystem\exceptions\UnableToReadFile;
use filesystem\exceptions\UnableToRetrieveMetadata;
use filesystem\exceptions\UnableToSetVisibility;
use filesystem\exceptions\UnableToWriteFile;
use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Локальная файловая система
 * Класс имеет тот же интерфейс что и \League\Flysystem\Filesystem, что позволяет использовать его по-умолчанию, без необходимости устанавливать
 * League\Flysystem
 * @package Files
 */
class LocalFilesystem
{
    public const OPTION_VISIBILITY = 'visibility';
    public const OPTION_DIRECTORY_VISIBILITY = 'directory_visibility';

    public const LIST_SHALLOW = false;
    public const LIST_DEEP = true;

    /**
     * @var int
     */
    public const SKIP_LINKS = 0001;

    /**
     * @var int
     */
    public const DISALLOW_LINKS = 0002;

    //private Config $config;
    //private PathNormalizer $pathNormalizer;

    protected string $rootDirectory = '';

    private int $filePublic = 0644;
    private int $filePrivate = 0600;
    private int $directoryPublic = 0755;
    private int $directoryPrivate = 0700;

    //private string $defaultDirectoryVisibility = StorageAttributes::VISIBILITY_PRIVATE;
    private string $defaultDirectoryVisibility = StorageAttributes::VISIBILITY_PUBLIC;

    protected string $baseUrl = '';

    private int $linkHandling = self::DISALLOW_LINKS;

    public function __construct(
        string $rootDirectory = ''
//        private FilesystemAdapter $adapter,
//        array $config = [],
//        PathNormalizer $pathNormalizer = null,
//        private ?PublicUrlGenerator $publicUrlGenerator = null,
//        private ?TemporaryUrlGenerator $temporaryUrlGenerator = null,
    ) {
        $this->rootDirectory = rtrim($rootDirectory, '\\/');
        if ($this->rootDirectory !== '') {
            $this->rootDirectory .= '/';
        }

        /** @todo файлы могут грузиться за пределы вебсервера */
        $this->baseUrl = rtrim($this->rootDirectory, '/') . '/';

//        $this->directoryPermissions = Cot::$cfg['dir_perms'];
//        $this->filePermissions = Cot::$cfg['file_perms'];

        $this->filePublic = Cot::$cfg['file_perms'];
        $this->directoryPublic = Cot::$cfg['dir_perms'];

//        $this->config = new Config($config);
//        $this->pathNormalizer = $pathNormalizer ?: new WhitespacePathNormalizer();
    }

    public function fileExists(string $location): bool
    {
        return is_file($this->prefixPath($this->normalizePath($location)));
    }

    public function directoryExists(string $location): bool
    {
        return is_dir($this->prefixPath($this->normalizePath($location)));
    }

    /**
     * Checks whether a file or directory exists
     */
    public function has(string $location): bool
    {
        return file_exists($this->prefixPath($this->normalizePath($location)));
    }

    /**
     * Directory you’re writing to will be created automatically
     * @param string $location Location of a file
     * @param string $contents File contents
     */
    public function write(string $location, string $contents): void
    {
        $this->writeToFile($location, $contents);
    }

    /**
     * In cases where you’re writing large files, using a resource to write a file is better.
     * A resource allows the contents of the file to be “streamed” to the new location, which has a very low memory footprint.
     * Directory you’re writing to will be created automatically
     * @param string $location Location of a file
     * @param resource $contents File resource
     */
    public function writeStream(string $location, $contents): void
    {
        /* @var resource $contents */
        $this->assertIsResource($contents);
        $this->rewindStream($contents);
        $this->writeToFile($location, $contents);
    }

    /**
     * Read the file contents in full as a string
     * @param string $location Location of a file
     * @return string File contents
     */
    public function read(string $location): string
    {
        $location = $this->prefixPath($this->normalizePath($location));
        error_clear_last();
        $contents = @file_get_contents($location);

        if ($contents === false) {
            throw UnableToReadFile::fromLocation($location, error_get_last()['message'] ?? '');
        }

        return $contents;
    }

    /**
     * Read the file contents as a stream, by obtaining a resource
     * Using the resource allows you to stream the contents to a destination (local or to another filesystem) in order to keep memory usage low
     * @param string $location Location of a file
     * @return resource File contents handle
     */
    public function readStream(string $location)
    {
        $location = $this->prefixPath($this->normalizePath($location));
        error_clear_last();
        $contents = @fopen($location, 'rb');

        if ($contents === false) {
            throw UnableToReadFile::fromLocation($location, error_get_last()['message'] ?? '');
        }

        return $contents;
    }

    public function delete(string $location): void
    {
        $prefixedLocation = $this->prefixPath($this->normalizePath($location));
        if (!file_exists($prefixedLocation)) {
            return;
        }

        error_clear_last();

        if (!@unlink($prefixedLocation)) {
            throw UnableToDeleteFile::atLocation($prefixedLocation, error_get_last()['message'] ?? '');
        }
    }

    /**
     * Recursive remove directory
     * @param string $location
     * @return void
     */
    public function deleteDirectory(string $location): void
    {
        $prefixedLocation = $this->prefixPath($this->normalizePath($location));
        if ($prefixedLocation === '' || !is_dir($prefixedLocation)) {
            return;
        }

        $contents = $this->listDirectoryRecursively($prefixedLocation, RecursiveIteratorIterator::CHILD_FIRST);
        /** @var SplFileInfo $file */
        foreach ($contents as $file) {
            if (!$this->deleteFileInfoObject($file)) {
                throw UnableToDeleteDirectory::atLocation($prefixedLocation, "Unable to delete file at " . $file->getPathname());
            }
        }
        unset($contents);

        if (!@rmdir($prefixedLocation)) {
            throw UnableToDeleteDirectory::atLocation($prefixedLocation, error_get_last()['message'] ?? '');
        }
    }

    public function createDirectory(string $location, array $config = []): void
    {
        $prefixedLocation = $this->prefixPath($this->normalizePath($location));

        $visibility = null;
        if (isset($config[static::OPTION_VISIBILITY])) {
            $visibility = $config[static::OPTION_VISIBILITY];
        } elseif (isset($config[static::OPTION_DIRECTORY_VISIBILITY])) {
            $visibility = $config[static::OPTION_DIRECTORY_VISIBILITY];
        }
        $visibility = $visibility ?? $this->defaultDirectoryVisibility;
        $permissions = $this->directoryVisibilityToPermissions($visibility);

        if (is_dir($prefixedLocation)) {
            $this->setPermissions($prefixedLocation, $permissions);
            return;
        }

        error_clear_last();

        if (!@mkdir($prefixedLocation, $permissions, true)) {
            throw UnableToCreateDirectory::atLocation($prefixedLocation, error_get_last()['message'] ?? '');
        }
    }

    /**
     * Listing directory contents
     * @param string $location Location of a directory
     * @param bool $deep Recursive or not (default false)
     * @return DirectoryListing<StorageAttributes>
     */
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW): ?DirectoryListing
    {
        $listing = $this->listDirectoryContents($location, $deep);
        return new DirectoryListing($this->pipeListing($location, $deep, $listing));
    }

    private function listDirectoryContents(string $location, bool $deep): iterable
    {
        $path = $this->prefixPath($this->normalizePath($location));

        if (!is_dir($path)) {
            return;
        }

        /** @var SplFileInfo[] $iterator */
        $iterator = $deep ? $this->listDirectoryRecursively($path) : $this->listDirectory($path);

        foreach ($iterator as $fileInfo) {
            $pathName = $fileInfo->getPathname();

            try {
                if ($fileInfo->isLink()) {
                    if ($this->linkHandling & self::SKIP_LINKS) {
                        continue;
                    }
                    throw SymbolicLinkEncountered::atLocation($pathName);
                }

                $path = $this->stripPathPrefix($pathName);
                $lastModified = $fileInfo->getMTime();
                $isDirectory = $fileInfo->isDir();
                $permissions = octdec(substr(sprintf('%o', $fileInfo->getPerms()), -4));
                $visibility = $isDirectory ? $this->directoryPermissionsToVisibility($permissions) : $this->filePermissionsToVisibility($permissions);

                yield $isDirectory
                    ? new DirectoryAttributes(str_replace('\\', '/', $path), $visibility, $lastModified)
                    : new FileAttributes(
                        str_replace('\\', '/', $path),
                        $fileInfo->getSize(),
                        $visibility,
                        $lastModified
                    );
            } catch (Throwable $exception) {
                if (file_exists($pathName)) {
                    throw $exception;
                }
            }
        }
    }

    private function listDirectory(string $location): Generator
    {
        $iterator = new DirectoryIterator($location);
        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }

            yield $item;
        }
    }

    private function listDirectoryRecursively(string $path, int $mode = RecursiveIteratorIterator::SELF_FIRST): Generator
    {
        if (!is_dir($path)) {
            return;
        }
        yield from new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            $mode
        );
    }

    private function pipeListing(string $location, bool $deep, iterable $listing): Generator
    {
        try {
            foreach ($listing as $item) {
                yield $item;
            }
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($location, $deep, $exception);
        }
    }

    /**
     * It will always overwrite the target location, and parent directories are always created
     * @param string $source Location of a file
     * @param string $destination New location of the file
     * @param array $config
     * @return void
     */
    public function move(string $source, string $destination, array $config = []): void
    {
        $sourcePath = $this->prefixPath($this->normalizePath($source));
        $destinationPath = $this->prefixPath($this->normalizePath($destination));

        $visibility = $config[static::OPTION_DIRECTORY_VISIBILITY] ?? $this->defaultDirectoryVisibility;
        $permissions = $this->directoryVisibilityToPermissions($visibility);

        $this->ensureDirectoryExists(dirname($destinationPath), $permissions);

        if (!@rename($sourcePath, $destinationPath)) {
            throw UnableToMoveFile::fromLocationTo($sourcePath, $destinationPath);
        }
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $sourcePath = $this->prefixPath($this->normalizePath($source));
        $destinationPath = $this->prefixPath($this->normalizePath($destination));

        if ($sourcePath === $destinationPath) {
            throw UnableToCopyFile::sourceAndDestinationAreTheSame($source, $destination);
        }

        $visibility = $config[static::OPTION_DIRECTORY_VISIBILITY] ?? $this->defaultDirectoryVisibility;
        $permissions = $this->directoryVisibilityToPermissions($visibility);

        $this->ensureDirectoryExists(dirname($destinationPath), $permissions);

        if (!@copy($sourcePath, $destinationPath)) {
            throw UnableToCopyFile::because(error_get_last()['message'] ?? 'unknown', $source, $destination);
        }
    }

    public function lastModified(string $path): int
    {
        $pathPrefixed = $this->prefixPath($this->normalizePath($path));

        error_clear_last();
        $lastModified = @filemtime($pathPrefixed);
        if ($lastModified === false) {
            throw UnableToRetrieveMetadata::lastModified($path, error_get_last()['message'] ?? '');
        }

        return $lastModified;
    }

    public function fileSize(string $path): int
    {
        $pathPrefixed = $this->prefixPath($this->normalizePath($path));

        error_clear_last();

        if (is_file($pathPrefixed) && ($fileSize = @filesize($pathPrefixed)) !== false) {
            return $fileSize;
        }

        throw UnableToRetrieveMetadata::fileSize($path, error_get_last()['message'] ?? '');
    }

    public function mimeType(string $path): string
    {
        $pathPrefixed = $this->prefixPath($this->normalizePath($path));

        error_clear_last();

        if (!is_file($pathPrefixed)) {
            throw UnableToRetrieveMetadata::mimeType($pathPrefixed, 'No such file exists.');
        }

        $mimeType = @mime_content_type($pathPrefixed);

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, error_get_last()['message'] ?? '');
        }

        return $mimeType;
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $pathPrefixed = $this->prefixPath($this->normalizePath($path));
        $permissions = is_dir($pathPrefixed) ? $this->directoryVisibilityToPermissions($visibility) : $this->fileVisibilityToPermissions($visibility);
        $this->setPermissions($pathPrefixed, $permissions);
    }

    public function visibility(string $path): string
    {
        $pathPrefixed = $this->prefixPath($this->normalizePath($path));

        clearstatcache(false, $pathPrefixed);
        error_clear_last();
        $fileperms = @fileperms($pathPrefixed);

        if ($fileperms === false) {
            throw UnableToRetrieveMetadata::visibility($path, error_get_last()['message'] ?? '');
        }

        $permissions = $fileperms & 0777;
        $visibility = $this->filePermissionsToVisibility($permissions);

        return $visibility;
    }

    public function publicUrl(string $path, array $config = []): string
    {
        return $this->baseUrl . $path;
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        throw UnableToGenerateTemporaryUrl::noGeneratorConfigured($path);
    }

    public function checksum(string $path, array $config = []): string
    {
        $pathPrefixed = $this->prefixPath($this->normalizePath($path));

        $algo = $config['checksum_algo'] ?? 'md5';

        error_clear_last();
        $checksum = @hash_file($algo, $pathPrefixed);

        if ($checksum === false) {
            throw new UnableToProvideChecksum(error_get_last()['message'] ?? '', $path);
        }

        return $checksum;
     }

    private function resolvePublicUrlGenerator(): ?PublicUrlGenerator
    {
        return null;
    }

    /**
     * @param mixed $contents
     */
    private function assertIsResource($contents): void
    {
        if (is_resource($contents) === false) {
            throw new InvalidStreamProvided(
                "Invalid stream provided, expected stream resource, received " . gettype($contents)
            );
        } elseif ($type = get_resource_type($contents) !== 'stream') {
            throw new InvalidStreamProvided(
                "Invalid stream provided, expected stream resource, received resource of type " . $type
            );
        }
    }

    /**
     * @param resource $resource
     */
    private function rewindStream($resource): void
    {
        if (ftell($resource) !== 0 && stream_get_meta_data($resource)['seekable']) {
            rewind($resource);
        }
    }

    /**
     * @param string $path
     * @return string
     */
    private function normalizePath($path)
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @param $path
     * @return string
     */
    private function prefixPath($path)
    {
        return $this->rootDirectory . ltrim($path, '\\/');
    }

    private function stripPathPrefix(string $path): string
    {
        /* @var string */
        return substr($path, strlen($this->rootDirectory));
    }

    /**
     * @param resource|string $contents
     */
    protected function writeToFile(string $path, $contents): void
    {
        $prefixedLocation = $this->prefixPath($this->normalizePath($path));
        $this->ensureDirectoryExists(dirname($prefixedLocation));

        error_clear_last();

        if (@file_put_contents($prefixedLocation, $contents) === false) {
            throw UnableToWriteFile::atLocation($path, error_get_last()['message'] ?? '');
        }
    }

    protected function ensureDirectoryExists(string $dirname, ?int $permissions = null): void
    {
        if (is_dir($dirname)) {
            return;
        }

        error_clear_last();

        $permissions = $permissions ?? $this->directoryVisibilityToPermissions($this->defaultDirectoryVisibility);

        if (!@mkdir($dirname, $permissions, true)) {
            $mkdirError = error_get_last();
        }

        clearstatcache(true, $dirname);

        if (!is_dir($dirname)) {
            $errorMessage = $mkdirError['message'] ?? '';

            throw UnableToCreateDirectory::atLocation($dirname, $errorMessage);
        }
    }

    protected function deleteFileInfoObject(SplFileInfo $file): bool
    {
        switch ($file->getType()) {
            case 'dir':
                return @rmdir((string) $file->getRealPath());
            case 'link':
                return @unlink((string) $file->getPathname());
            default:
                return @unlink((string) $file->getRealPath());
        }
    }

    private function setPermissions(string $location, int $visibility): void
    {
        error_clear_last();
        if (!@chmod($location, $visibility)) {
            $extraMessage = error_get_last()['message'] ?? '';
            throw UnableToSetVisibility::atLocation($location, $extraMessage);
        }
    }

    /**
     * @see \League\Flysystem\UnixVisibility\PortableVisibilityConverter::forFile()
     */
    private function fileVisibilityToPermissions(string $visibility): int
    {
        $this->validateVisibility($visibility);

        return $visibility === StorageAttributes::VISIBILITY_PUBLIC
            ? $this->filePublic
            : $this->filePrivate;
    }

    /**
     * @see \League\Flysystem\UnixVisibility\PortableVisibilityConverter::forDirectory()
     */
    public function directoryVisibilityToPermissions(string $visibility): int
    {
        $this->validateVisibility($visibility);

        return $visibility === StorageAttributes::VISIBILITY_PUBLIC
            ? $this->directoryPublic
            : $this->directoryPrivate;
    }

    /**
     * @see \League\Flysystem\UnixVisibility\PortableVisibilityConverter::inverseForFile()
     */
    public function filePermissionsToVisibility(int $visibility): string
    {
        if ($visibility === $this->filePublic) {
            return StorageAttributes::VISIBILITY_PUBLIC;
        } elseif ($visibility === $this->filePrivate) {
            return StorageAttributes::VISIBILITY_PRIVATE;
        }

        return StorageAttributes::VISIBILITY_PUBLIC; // default
    }

    /**
     * @see \League\Flysystem\UnixVisibility\PortableVisibilityConverter::inverseForDirectory()
     */
    public function directoryPermissionsToVisibility(int $visibility): string
    {
        if ($visibility === $this->directoryPublic) {
            return StorageAttributes::VISIBILITY_PUBLIC;
        } elseif ($visibility === $this->directoryPrivate) {
            return StorageAttributes::VISIBILITY_PRIVATE;
        }

        return StorageAttributes::VISIBILITY_PUBLIC; // default
    }

    private function validateVisibility(string $visibility): void
    {
        if ($visibility !== StorageAttributes::VISIBILITY_PUBLIC && $visibility !== StorageAttributes::VISIBILITY_PRIVATE) {
            $className = StorageAttributes::class;
            throw InvalidVisibilityProvided::withVisibility(
                $visibility,
                "either {$className}::PUBLIC or {$className}::PRIVATE"
            );
        }
    }
}
