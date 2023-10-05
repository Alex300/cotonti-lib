<?php

declare(strict_types=1);

namespace filesystem;

use Cot;
use filesystem\exceptions\InvalidStreamProvided;
use filesystem\exceptions\UnableToCreateDirectory;
use filesystem\exceptions\UnableToDeleteDirectory;
use filesystem\exceptions\UnableToDeleteFile;
use filesystem\exceptions\UnableToMoveFile;
use filesystem\exceptions\UnableToReadFile;
use filesystem\exceptions\UnableToRetrieveMetadata;
use filesystem\exceptions\UnableToSetVisibility;
use filesystem\exceptions\UnableToWriteFile;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Traversable;

/**
 * Локальная файловая система
 * Класс имеет тот же интерфейс что и \League\Flysystem\Filesystem, что позволяет использовать его по-умолчанию, без необходимости устанавливать
 * League\Flysystem
 * @package Files
 */
class LocalFilesystem
{
    public const LIST_SHALLOW = false;
    public const LIST_DEEP = true;

    //private Config $config;
    //private PathNormalizer $pathNormalizer;

    protected string $rootDirectory = '';

    protected ?int $directoryPermissions = null;

    protected string $baseUrl = '';

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

        $this->directoryPermissions = Cot::$cfg['dir_perms'];

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

//        $objects = scandir($prefixedLocation);
//        foreach ($objects as $object) {
//            if ($object != '.' && $object != '..') {
//                if (filetype($prefixedLocation . '/' . $object) === 'dir') {
//                    $this->deleteDirectory($prefixedLocation . '/' . $object);
//                } else {
//                    unlink($prefixedLocation . '/' . $object);
//                }
//            }
//        }
//        reset($objects);
//        rmdir($prefixedLocation);

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
        $permissions = $this->directoryPermissions;

        if (is_dir($prefixedLocation)) {
            $this->setPermissions($prefixedLocation, $permissions);
            return;
        }

        error_clear_last();

        if (!@mkdir($location, $permissions, true)) {
            throw UnableToCreateDirectory::atLocation($prefixedLocation, error_get_last()['message'] ?? '');
        }
    }

    /**
     * Listing directory contents
     * @param string $location Location of a directory
     * @param bool $deep Recursive or not (default false)
     * @todo
     */
    public function listContents(string $location, bool $deep = self::LIST_SHALLOW)
    {
//        $path = $this->pathNormalizer->normalizePath($location);
//        $listing = $this->adapter->listContents($path, $deep);


    }

    private function pipeListing(string $location, bool $deep, iterable $listing)
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

        $this->ensureDirectoryExists(dirname($destinationPath), $config['visibility'] ?? null);

        if (!@rename($sourcePath, $destinationPath)) {
            throw UnableToMoveFile::fromLocationTo($sourcePath, $destinationPath);
        }
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->adapter->copy(
            $this->pathNormalizer->normalizePath($source),
            $this->pathNormalizer->normalizePath($destination),
            $this->config->extend($config)
        );
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
        return $this->adapter->fileSize($this->pathNormalizer->normalizePath($path))->fileSize();
    }

    public function mimeType(string $path): string
    {
        return $this->adapter->mimeType($this->pathNormalizer->normalizePath($path))->mimeType();
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->adapter->setVisibility($this->pathNormalizer->normalizePath($path), $visibility);
    }

    public function visibility(string $path): string
    {
        return $this->adapter->visibility($this->pathNormalizer->normalizePath($path))->visibility();
    }

    public function publicUrl(string $path, array $config = []): string
    {
        return $this->baseUrl . $path;
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        $generator = $this->temporaryUrlGenerator ?: $this->adapter;

        if ($generator instanceof TemporaryUrlGenerator) {
            return $generator->temporaryUrl($path, $expiresAt, $this->config->extend($config));
        }

        throw UnableToGenerateTemporaryUrl::noGeneratorConfigured($path);
    }

    public function checksum(string $path, array $config = []): string
    {
        $config = $this->config->extend($config);

        if ( ! $this->adapter instanceof ChecksumProvider) {
            return $this->calculateChecksumFromStream($path, $config);
        }

        try {
            return $this->adapter->checksum($path, $config);
        } catch (ChecksumAlgoIsNotSupported) {
            return $this->calculateChecksumFromStream($path, $config);
        }
    }

    private function resolvePublicUrlGenerator(): ?PublicUrlGenerator
    {
        if ($publicUrl = $this->config->get('public_url')) {
            return match (true) {
                is_array($publicUrl) => new ShardedPrefixPublicUrlGenerator($publicUrl),
                default => new PrefixPublicUrlGenerator($publicUrl),
            };
        }

        if ($this->adapter instanceof PublicUrlGenerator) {
            return $this->adapter;
        }

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

    protected function ensureDirectoryExists(string $dirname, ?int $visibility = null)
    {
        if (is_dir($dirname)) {
            return;
        }

        error_clear_last();

        $visibility = !empty($visibility) ? $visibility : $this->directoryPermissions;

        if (!@mkdir($dirname, $visibility, true)) {
            $mkdirError = error_get_last();
        }

        clearstatcache(true, $dirname);

        if (!is_dir($dirname)) {
            $errorMessage = $mkdirError['message'] ?? '';

            throw UnableToCreateDirectory::atLocation($dirname, $errorMessage);
        }
    }

    protected function listDirectoryRecursively(string $path, int $mode = RecursiveIteratorIterator::SELF_FIRST): Traversable
    {
        if (!is_dir($path)) {
            return;
        }

        yield from new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            $mode
        );
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
}
