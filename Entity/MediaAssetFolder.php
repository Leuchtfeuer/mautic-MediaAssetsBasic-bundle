<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository\MediaAssetFolderRepository;

class MediaAssetFolder
{
    private ?int $id = null;

    private ?self $parent = null;

    private string $relativePath = '';

    private string $name = '';

    private ?\DateTimeImmutable $missingSince = null;

    /**
     * @param ORM\ClassMetadata<self> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('lf_media_asset_folders')
            ->setCustomRepositoryClass(MediaAssetFolderRepository::class)
            ->addUniqueConstraint(['relative_path'], 'lf_mab_folder_path');

        $builder->addId();

        $builder->createManyToOne('parent', self::class)
            ->addJoinColumn('parent_id', 'id', true, false, 'CASCADE')
            ->build();

        $builder->addNamedField('relativePath', Types::STRING, 'relative_path');
        $builder->addNamedField('name', Types::STRING, 'name');
        $builder->addNamedField('missingSince', Types::DATETIME_IMMUTABLE, 'missing_since', true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

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
