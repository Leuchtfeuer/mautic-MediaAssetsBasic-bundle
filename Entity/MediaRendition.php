<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionState;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaRenditionRepository;

class MediaRendition
{
    private ?int $id = null;

    private ?MediaAssetFile $file = null;

    private string $preset = '';

    private string $format = '';

    private int $width = 0;

    private int $height = 0;

    private int $bytes = 0;

    private string $storagePath = '';

    private string $state = '';

    private ?string $failureReason = null;

    private ?\DateTimeImmutable $dateAdded = null;

    private ?\DateTimeImmutable $dateModified = null;

    /**
     * @param ORM\ClassMetadata<self> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('lf_media_renditions')
            ->setCustomRepositoryClass(MediaRenditionRepository::class)
            ->addUniqueConstraint(['file_id', 'preset'], 'lf_mab_rendition_file_preset')
            ->addIndex(['storage_path'], 'lf_mab_rendition_path')
            ->addIndex(['state'], 'lf_mab_rendition_state');

        $builder->addId();

        $builder->createManyToOne('file', MediaAssetFile::class)
            ->addJoinColumn('file_id', 'id', false, false, 'CASCADE')
            ->build();

        $builder->addNamedField('preset', Types::STRING, 'preset');
        $builder->addNamedField('format', Types::STRING, 'format');
        $builder->addNamedField('width', Types::INTEGER, 'width');
        $builder->addNamedField('height', Types::INTEGER, 'height');
        $builder->addNamedField('bytes', Types::INTEGER, 'bytes');
        $builder->addNamedField('storagePath', Types::STRING, 'storage_path');
        $builder->addNamedField('state', Types::STRING, 'state');
        $builder->addNamedField('failureReason', Types::TEXT, 'failure_reason', true);
        $builder->addNamedField('dateAdded', Types::DATETIME_IMMUTABLE, 'date_added', true);
        $builder->addNamedField('dateModified', Types::DATETIME_IMMUTABLE, 'date_modified', true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getFile(): ?MediaAssetFile
    {
        return $this->file;
    }

    public function setFile(?MediaAssetFile $file): self
    {
        $this->file = $file;

        return $this;
    }

    public function getPreset(): RenditionPreset
    {
        return RenditionPreset::from($this->preset);
    }

    public function setPreset(RenditionPreset $preset): self
    {
        $this->preset = $preset->value;

        return $this;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): self
    {
        $this->format = $format;

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

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function setStoragePath(string $storagePath): self
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    public function getState(): RenditionState
    {
        return RenditionState::from($this->state);
    }

    public function setState(RenditionState $state): self
    {
        $this->state = $state->value;

        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function setFailureReason(?string $failureReason): self
    {
        $this->failureReason = $failureReason;

        return $this;
    }

    public function getDateAdded(): ?\DateTimeImmutable
    {
        return $this->dateAdded;
    }

    public function setDateAdded(?\DateTimeImmutable $dateAdded): self
    {
        $this->dateAdded = $dateAdded;

        return $this;
    }

    public function getDateModified(): ?\DateTimeImmutable
    {
        return $this->dateModified;
    }

    public function setDateModified(?\DateTimeImmutable $dateModified): self
    {
        $this->dateModified = $dateModified;

        return $this;
    }

    public function isReady(): bool
    {
        return RenditionState::Ready === $this->getState();
    }

    public function isNotNeeded(): bool
    {
        return RenditionState::NotNeeded === $this->getState();
    }
}
