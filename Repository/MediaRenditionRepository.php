<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaRendition;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionState;

/**
 * @extends ServiceEntityRepository<MediaRendition>
 */
class MediaRenditionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MediaRendition::class);
    }

    public function findOneForFilePreset(MediaAssetFile $file, RenditionPreset $preset): ?MediaRendition
    {
        return $this->findOneBy([
            'file'   => $file,
            'preset' => $preset->value,
        ]);
    }

    public function findReadyForFilePreset(MediaAssetFile $file, RenditionPreset $preset): ?MediaRendition
    {
        return $this->findOneBy([
            'file'   => $file,
            'preset' => $preset->value,
            'state'  => RenditionState::Ready->value,
        ]);
    }

    /**
     * @return list<MediaRendition>
     */
    public function findForFile(MediaAssetFile $file): array
    {
        return $this->findBy(['file' => $file]);
    }

    /**
     * @param list<int> $fileIds
     *
     * @return array<int, array<string, MediaRendition>> fileId => [preset => row]
     */
    public function mapReadyByFileIds(array $fileIds): array
    {
        if ([] === $fileIds) {
            return [];
        }

        /** @var list<MediaRendition> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.file IN (:ids)')
            ->andWhere('r.state = :ready')
            ->setParameter('ids', $fileIds)
            ->setParameter('ready', RenditionState::Ready->value)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $fileId = $row->getFile()?->getId();
            if (null === $fileId) {
                continue;
            }
            $map[$fileId][$row->getPreset()->value] = $row;
        }

        return $map;
    }
}
