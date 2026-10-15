<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Entity\CommonRepository;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Entity\MediaAssetFile;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\FilenameSearch;
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

    public function findOldestActiveByChecksum(string $checksum): ?MediaAssetFile
    {
        if ('' === $checksum) {
            return null;
        }

        /** @var list<MediaAssetFile> $matches */
        $matches = $this->createQueryBuilder('f')
            ->where('f.checksum = :checksum')
            ->andWhere('f.missingSince IS NULL')
            ->setParameter('checksum', $checksum)
            ->getQuery()
            ->getResult();

        if ([] === $matches) {
            return null;
        }

        usort(
            $matches,
            static function (MediaAssetFile $left, MediaAssetFile $right): int {
                $leftAdded  = $left->getDateAdded();
                $rightAdded = $right->getDateAdded();
                if ($leftAdded instanceof \DateTimeImmutable && $rightAdded instanceof \DateTimeImmutable) {
                    $cmp = $leftAdded <=> $rightAdded;
                    if (0 !== $cmp) {
                        return $cmp;
                    }
                } elseif ($leftAdded instanceof \DateTimeImmutable) {
                    return -1;
                } elseif ($rightAdded instanceof \DateTimeImmutable) {
                    return 1;
                }

                return ($left->getId() ?? 0) <=> ($right->getId() ?? 0);
            }
        );

        return $matches[0];
    }

    public function hasOtherActiveWithChecksum(string $checksum, ?string $excludeRelativePath = null): bool
    {
        if ('' === $checksum) {
            return false;
        }

        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.checksum = :checksum')
            ->andWhere('f.missingSince IS NULL')
            ->setParameter('checksum', $checksum);

        if (null !== $excludeRelativePath && '' !== $excludeRelativePath) {
            $qb->andWhere('f.relativePath != :exclude')
                ->setParameter('exclude', $excludeRelativePath);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
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
     * Search filenames under a folder tree (or everywhere when $folderRelative is '').
     * Ranking (tier / current-folder / mtime / id) is applied in PHP for correct tier rules.
     *
     * @return array{images: list<MediaAssetFile>, total: int}
     */
    public function searchInTree(
        string $folderRelative,
        string $query,
        string $currentFolderRelative,
        int $page,
        int $limit,
        bool $allowSvg,
    ): array {
        $page   = max(1, $page);
        $limit  = max(1, $limit);
        $tokens = FilenameSearch::queryTokens($query);
        if ([] === $tokens) {
            return ['images' => [], 'total' => 0];
        }

        $qb = $this->createQueryBuilder('f')
            ->where('f.missingSince IS NULL');

        if ('' !== $folderRelative) {
            $qb->andWhere('f.relativePath LIKE :treePrefix')
                ->setParameter('treePrefix', $this->escapeLike($folderRelative).'/%');
        }

        if (!$allowSvg) {
            $qb->andWhere('f.mime != :svg')
                ->setParameter('svg', ImageInspector::SVG_MIME);
        }

        foreach ($tokens as $index => $token) {
            $qb->andWhere('LOWER(f.filename) LIKE :token'.$index)
                ->setParameter('token'.$index, '%'.$this->escapeLike(mb_strtolower($token)).'%');
        }

        /** @var list<MediaAssetFile> $matches */
        $matches = $qb->getQuery()->getResult();

        usort(
            $matches,
            static fn (MediaAssetFile $left, MediaAssetFile $right): int => FilenameSearch::compare(
                $left->getFilename(),
                $left->getRelativePath(),
                $left->getFileMtime(),
                $left->getId(),
                $right->getFilename(),
                $right->getRelativePath(),
                $right->getFileMtime(),
                $right->getId(),
                $query,
                $currentFolderRelative,
            )
        );

        $total = count($matches);

        return [
            'images' => array_slice($matches, ($page - 1) * $limit, $limit),
            'total'  => $total,
        ];
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
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
