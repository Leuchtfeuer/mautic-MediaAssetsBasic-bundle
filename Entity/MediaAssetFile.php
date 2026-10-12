<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFileRepository;

class MediaAssetFile
{
    private ?int $id = null;

    private ?MediaAssetFolder $folder = null;

    private string $relativePath = '';

    private string $filename = '';

    private string $mime = '';

    private int $width = 0;

    private int $height = 0;

    private int $bytes = 0;

    private int $fileMtime = 0;

    private ?\DateTimeImmutable $missingSince = null;

    /**
     * @param ORM\ClassMetadata<self> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('lf_media_asset_files')
            ->setCustomRepositoryClass(MediaAssetFileRepository::class)
            ->addUniqueConstraint(['relative_path'], 'lf_mab_file_path')
            ->addIndex(['file_mtime'], 'lf_mab_file_mtime');

        $builder->addId();

        $builder->createManyToOne('folder', MediaAssetFolder::class)
            ->addJoinColumn('folder_id', 'id', true, false, 'SET NULL')
            ->build();

        $builder->addNamedField('relativePath', Types::STRING, 'relative_path');
        $builder->addNamedField('filename', Types::STRING, 'filename');
        $builder->addNamedField('mime', Types::STRING, 'mime');
        $builder->addNamedField('width', Types::INTEGER, 'width');
        $builder->addNamedField('height', Types::INTEGER, 'height');
        $builder->addNamedField('bytes', Types::INTEGER, 'bytes');
        $builder->addNamedField('fileMtime', Types::INTEGER, 'file_mtime');
        $builder->addNamedField('missingSince', Types::DATETIME_IMMUTABLE, 'missing_since', true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFolder(): ?MediaAssetFolder
    {
        return $this->folder;
    }

    public function setFolder(?MediaAssetFolder $folder): self
    {
        $this->folder = $folder;

        return $this;
    }

    public function getRelativePath(): string
    {
        return $this->relativePath;
    }

    public function setRelativePath(string $relativePath): self
    {
        $this->relativePath = $relativePath;

        return $this;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getMime(): string
    {
        return $this->mime;
    }

    public function setMime(string $mime): self
    {
        $this->mime = $mime;

        return $this;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function setWidth(int $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function setHeight(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function getBytes(): int
    {
        return $this->bytes;
    }

    public function setBytes(int $bytes): self
    {
        $this->bytes = $bytes;

        return $this;
    }

    public function getFileMtime(): int
    {
        return $this->fileMtime;
    }

    public function setFileMtime(int $fileMtime): self
    {
        $this->fileMtime = $fileMtime;

        return $this;
    }

    public function getMissingSince(): ?\DateTimeImmutable
    {
        return $this->missingSince;
    }

    public function setMissingSince(?\DateTimeImmutable $missingSince): self
    {
        $this->missingSince = $missingSince;

        return $this;
    }

    public function isActive(): bool
    {
        return null === $this->missingSince;
    }
}
