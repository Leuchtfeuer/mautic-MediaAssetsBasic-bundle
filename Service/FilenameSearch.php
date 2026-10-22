<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service;

/**
 * Name-only search matching and result tiers for the media picker.
 *
 * Tiers (lower is better): exact → starts-with → whole-token → substring.
 * Multi-word queries are AND (order-independent) across the filename.
 */
final class FilenameSearch
{
    public const MIN_QUERY_LENGTH = 2;

    public const TIER_EXACT = 1;

    public const TIER_PREFIX = 2;

    public const TIER_TOKEN = 3;

    public const TIER_SUBSTRING = 4;

    public const TIER_NONE = 99;

    public static function isActive(string $query): bool
    {
        return mb_strlen(trim($query)) >= self::MIN_QUERY_LENGTH;
    }

    /**
     * @return list<string>
     */
    public static function queryTokens(string $query): array
    {
        $parts = preg_split('/\s+/u', trim($query)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    /**
     * @return list<string>
     */
    public static function filenameTokens(string $filename): array
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/u', mb_strtolower($filename)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    public static function matches(string $filename, string $query): bool
    {
        $tokens = self::queryTokens($query);
        if ([] === $tokens) {
            return false;
        }

        $haystack = mb_strtolower($filename);
        foreach ($tokens as $token) {
            if (!str_contains($haystack, mb_strtolower($token))) {
                return false;
            }
        }

        return true;
    }

    public static function tier(string $filename, string $query): int
    {
        $trimmed = trim($query);
        if ('' === $trimmed || !self::matches($filename, $trimmed)) {
            return self::TIER_NONE;
        }

        $name   = mb_strtolower($filename);
        $needle = mb_strtolower($trimmed);

        if ($name === $needle) {
            return self::TIER_EXACT;
        }

        if (str_starts_with($name, $needle)) {
            return self::TIER_PREFIX;
        }

        $queryTokens    = array_map(mb_strtolower(...), self::queryTokens($trimmed));
        $filenameTokens = self::filenameTokens($filename);
        $allTokensMatch = true;
        foreach ($queryTokens as $token) {
            if (!in_array($token, $filenameTokens, true)) {
                $allTokensMatch = false;
                break;
            }
        }

        if ($allTokensMatch) {
            return self::TIER_TOKEN;
        }

        return self::TIER_SUBSTRING;
    }

    /**
     * Parent folder of a relative image path ('' for root files).
     */
    public static function parentFolder(string $relativePath): string
    {
        $slash = strrpos($relativePath, '/');

        return false === $slash ? '' : substr($relativePath, 0, $slash);
    }

    /**
     * Sort comparator: tier ASC, is_current_folder DESC, fileMtime DESC, id DESC.
     */
    public static function compare(
        string $leftFilename,
        string $leftRelativePath,
        int $leftMtime,
        ?int $leftId,
        string $rightFilename,
        string $rightRelativePath,
        int $rightMtime,
        ?int $rightId,
        string $query,
        string $currentFolderRelative,
    ): int {
        $tierCmp = self::tier($leftFilename, $query) <=> self::tier($rightFilename, $query);
        if (0 !== $tierCmp) {
            return $tierCmp;
        }

        $leftCurrent  = self::parentFolder($leftRelativePath) === $currentFolderRelative ? 1 : 0;
        $rightCurrent = self::parentFolder($rightRelativePath) === $currentFolderRelative ? 1 : 0;
        $currentCmp   = $rightCurrent <=> $leftCurrent;
        if (0 !== $currentCmp) {
            return $currentCmp;
        }

        $mtimeCmp = $rightMtime <=> $leftMtime;
        if (0 !== $mtimeCmp) {
            return $mtimeCmp;
        }

        return ($rightId ?? 0) <=> ($leftId ?? 0);
    }
}
