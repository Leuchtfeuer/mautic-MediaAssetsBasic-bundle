<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Entity\CommonRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFolder;

/**
 * @extends CommonRepository<MediaAssetFolder>
 */
class MediaAssetFolderRepository extends CommonRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        // Must pass the entity FQCN: CommonRepository strips "Repository" from the class name,
        // which breaks when this class lives under the Repository\ namespace.
        parent::__construct($registry, MediaAssetFolder::class);
    }

    public function getTableAlias(): string
    {
        return 'maf';
    }

    public function findOneByRelativePath(string $relativePath): ?MediaAssetFolder
    {
        return $this->findOneBy(['relativePath' => $relativePath]);
    }

    /**
     * @return list<MediaAssetFolder>
     */
    public function findActiveFolders(): array
    {
        /** @var list<MediaAssetFolder> $folders */
        $folders = $this->createQueryBuilder('f')
            ->where('f.missingSince IS NULL')
            ->getQuery()
            ->getResult();

        return $folders;
    }
}
