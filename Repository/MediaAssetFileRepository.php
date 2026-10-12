<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Entity\CommonRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;

/**
 * @extends CommonRepository<MediaAssetFile>
 */
class MediaAssetFileRepository extends CommonRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        // Must pass the entity FQCN: CommonRepository strips "Repository" from the class name,
        // which breaks when this class lives under the Repository\ namespace.
        parent::__construct($registry, MediaAssetFile::class);
    }

    public function getTableAlias(): string
    {
        return 'mafile';
    }

    public function findOneByRelativePath(string $relativePath): ?MediaAssetFile
    {
        return $this->findOneBy(['relativePath' => $relativePath]);
    }

    /**
     * @return array{images: list<MediaAssetFile>, total: int}
     */
    public function listInFolder(string $folderRelative, int $page, int $limit, bool $allowSvg): array
    {
        $page  = max(1, $page);
        $limit = max(1, $limit);

        $qb = $this->createQueryBuilder('f')
            ->where('f.missingSince IS NULL');

        if ('' === $folderRelative) {
            $qb->andWhere('f.folder IS NULL');
        } else {
            $qb->innerJoin('f.folder', 'folder')
                ->andWhere('folder.relativePath = :folder')
                ->andWhere('folder.missingSince IS NULL')
                ->setParameter('folder', $folderRelative);
        }

        if (!$allowSvg) {
            $qb->andWhere('f.mime != :svg')
                ->setParameter('svg', ImageInspector::SVG_MIME);
        }

        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(f.id)')->getQuery()->getSingleScalarResult();

        /** @var list<MediaAssetFile> $images */
        $images = $qb
            ->orderBy('f.fileMtime', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return [
            'images' => $images,
            'total'  => $total,
        ];
    }

    /**
     * @return list<MediaAssetFile>
     */
    public function findActiveFiles(): array
    {
        /** @var list<MediaAssetFile> $files */
        $files = $this->createQueryBuilder('f')
            ->where('f.missingSince IS NULL')
            ->getQuery()
            ->getResult();

        return $files;
    }

    /**
     * @return list<MediaAssetFile>
     */
    public function findAllIndexed(): array
    {
        /** @var list<MediaAssetFile> $files */
        $files = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f')
            ->from(MediaAssetFile::class, 'f')
            ->getQuery()
            ->getResult();

        return $files;
    }
}
