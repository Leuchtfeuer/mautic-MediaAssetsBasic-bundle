<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Enum\RenditionPreset;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageInspector;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageRenditionWriter;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\ImageUrls;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\MediaPath;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\RenditionPaths;
use PHPUnit\Framework\TestCase;

final class ImageRenditionWriterTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            $this->markTestSkipped('GD or Imagick required');
        }

        $directory = sys_get_temp_dir().'/lf-rendition-writer-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $this->directory = $directory;
    }

    protected function tearDown(): void
    {
        if ('' === $this->directory || !is_dir($this->directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testWritesStandardAndThumbForLargeJpeg(): void
    {
        $relative = 'brand/hero.jpg';
        $absolute = $this->directory.'/'.$relative;
        mkdir(dirname($absolute), 0775, true);
        $this->writeJpeg($absolute, 2000, 1200);

        $writer   = $this->writer();
        $standard = $writer->write($absolute, $relative, RenditionPreset::Standard, false);
        $thumb    = $writer->write($absolute, $relative, RenditionPreset::Thumb, false);

        $this->assertNotNull($standard);
        $this->assertNotNull($thumb);
        $this->assertSame('_renditions/brand/hero_1600.jpg', $standard->relativePath);
        $this->assertSame('_renditions/brand/hero_400.jpg', $thumb->relativePath);
        $this->assertTrue(is_file($standard->absolutePath));
        $this->assertTrue(is_file($thumb->absolutePath));
        $this->assertSame(1600, max($standard->width, $standard->height));
        $this->assertSame(400, max($thumb->width, $thumb->height));
        $this->assertGreaterThan(0, $standard->bytes);
        $this->assertGreaterThan(0, $thumb->bytes);
    }

    public function testSkipsStandardWhenImageAlreadySmaller(): void
    {
        $relative = 'small.jpg';
        $absolute = $this->directory.'/'.$relative;
        $this->writeJpeg($absolute, 800, 600);

        $standard = $this->writer()->write($absolute, $relative, RenditionPreset::Standard, false);
        $thumb    = $this->writer()->write($absolute, $relative, RenditionPreset::Thumb, false);

        $this->assertNull($standard);
        $this->assertNotNull($thumb);
        $this->assertSame(400, max($thumb->width, $thumb->height));
    }

    public function testSkipsStandardForAnimatedGifFlag(): void
    {
        $relative = 'anim.gif';
        $absolute = $this->directory.'/'.$relative;
        $this->writeGif($absolute, 500, 400);

        $standard = $this->writer()->write($absolute, $relative, RenditionPreset::Standard, true);
        $thumb    = $this->writer()->write($absolute, $relative, RenditionPreset::Thumb, true);

        $this->assertNull($standard);
        $this->assertNotNull($thumb);
    }

    private function writer(): ImageRenditionWriter
    {
        $helper = $this->createMock(PathsHelper::class);
        $helper->method('getImagePath')->willReturn($this->directory.'/');
        $helper->method('getSystemPath')->willReturn('media/images');
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->willReturn('');
        $paths = new MediaPath(new ImageUrls($helper, $parameters));

        return new ImageRenditionWriter(new RenditionPaths($paths), new ImageInspector());
    }

    private function writeJpeg(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 20, 40, 60);
        $this->assertNotFalse($color);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    private function writeGif(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 200, 100, 50);
        $this->assertNotFalse($color);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        imagegif($image, $path);
        imagedestroy($image);
    }
}
