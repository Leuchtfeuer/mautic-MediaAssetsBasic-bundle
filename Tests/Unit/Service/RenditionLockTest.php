<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Exception\RenditionBusyException;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Service\RenditionLock;
use PHPUnit\Framework\TestCase;

final class RenditionLockTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/lf-rendition-lock-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $lock = $this->tmpDir.'/lf_media_rendition.lock';
        if (is_file($lock)) {
            unlink($lock);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testWithLockRunsTheCallbackAndReturnsItsValue(): void
    {
        $lock = $this->lock(5);

        $result = $lock->withLock(static fn (float $deadline): string => 'ok-'.$deadline);

        $this->assertStringStartsWith('ok-', $result);
    }

    public function testFailFastThrowsWhenLockIsAlreadyHeld(): void
    {
        $holder = $this->lock(5);
        $waiter = $this->lock(5);

        $holder->withLock(function () use ($waiter): void {
            $this->expectException(RenditionBusyException::class);
            $waiter->withLock(static fn (): null => null, true);
        });
    }

    public function testAssertWithinDeadlineThrowsWhenExpired(): void
    {
        $lock = $this->lock(1);

        $this->expectException(RenditionBusyException::class);
        $lock->assertWithinDeadline(microtime(true) - 1);
    }

    private function lock(int $timeoutSeconds): RenditionLock
    {
        $paths = $this->createMock(PathsHelper::class);
        $paths->method('getSystemPath')->with('tmp', true)->willReturn($this->tmpDir);

        return new RenditionLock($paths, $timeoutSeconds, 3);
    }
}
