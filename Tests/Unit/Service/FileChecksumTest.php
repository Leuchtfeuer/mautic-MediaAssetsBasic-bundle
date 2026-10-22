<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\FileChecksum;
use PHPUnit\Framework\TestCase;

final class FileChecksumTest extends TestCase
{
    public function testHashesFileAndContentsConsistently(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lf-checksum');
        $this->assertIsString($path);
        $contents = 'media-assets-checksum';
        file_put_contents($path, $contents);

        try {
            $fromFile     = FileChecksum::hashFile($path);
            $fromContents = FileChecksum::hashContents($contents);
            $this->assertSame(hash('sha256', $contents), $fromFile);
            $this->assertSame($fromFile, $fromContents);
            $this->assertSame(64, strlen($fromFile));
        } finally {
            unlink($path);
        }
    }
}
