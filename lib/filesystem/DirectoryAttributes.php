<?php

declare(strict_types=1);

namespace filesystem;

class DirectoryAttributes implements StorageAttributes
{
    use ProxyArrayAccessToProperties;

    private string $type = StorageAttributes::TYPE_DIRECTORY;
    private ?string $visibility = null;
    private string $path;
    private ?int $lastModified = null;
    private array $extraMetadata = [];

    public function __construct(string $path, ?string $visibility = null, ?int $lastModified = null, array $extraMetadata = [])
    {
        $this->extraMetadata = $extraMetadata;
        $this->lastModified = $lastModified;
        $this->path = $path;
        $this->visibility = $visibility;
        $this->path = trim($this->path, '/');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function visibility(): ?string
    {
        return $this->visibility;
    }

    public function lastModified(): ?int
    {
        return $this->lastModified;
    }

    public function extraMetadata(): array
    {
        return $this->extraMetadata;
    }

    public function isFile(): bool
    {
        return false;
    }

    public function isDir(): bool
    {
        return true;
    }

    public function withPath(string $path): self
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    public static function fromArray(array $attributes): self
    {
        return new DirectoryAttributes(
            $attributes[StorageAttributes::ATTRIBUTE_PATH],
            $attributes[StorageAttributes::ATTRIBUTE_VISIBILITY] ?? null,
            $attributes[StorageAttributes::ATTRIBUTE_LAST_MODIFIED] ?? null,
            $attributes[StorageAttributes::ATTRIBUTE_EXTRA_METADATA] ?? []
        );
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
        return [
            StorageAttributes::ATTRIBUTE_TYPE => $this->type,
            StorageAttributes::ATTRIBUTE_PATH => $this->path,
            StorageAttributes::ATTRIBUTE_VISIBILITY => $this->visibility,
            StorageAttributes::ATTRIBUTE_LAST_MODIFIED => $this->lastModified,
            StorageAttributes::ATTRIBUTE_EXTRA_METADATA => $this->extraMetadata,
        ];
    }
}
