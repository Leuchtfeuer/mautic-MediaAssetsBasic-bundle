<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Tests\Unit\Command;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Command\IndexMediaCommand;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\MediaIndexer;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\MediaIndexRunSummary;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class IndexMediaCommandTest extends TestCase
{
    /** @var MediaIndexer&MockObject */
    private MockObject $index;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->index = $this->createMock(MediaIndexer::class);

        $application = new Application();
        $application->addCommand(new IndexMediaCommand($this->index));
        $this->tester = new CommandTester($application->find('leuchtfeuer:media:index-media'));
    }

    public function testDefaultRunPassesFalseFlags(): void
    {
        $this->index->expects($this->once())
            ->method('run')
            ->with(false, false, false)
            ->willReturn(new MediaIndexRunSummary(filesIndexed: 2, foldersIndexed: 1));

        $status = $this->tester->execute([]);

        $this->assertSame(0, $status);
        $display = $this->tester->getDisplay();
        $this->assertStringContainsString('Files indexed (new)', $display);
        $this->assertStringContainsString('of which duplicate checksum', $display);
        $this->assertStringContainsString('Renditions generated', $display);
        $this->assertStringContainsString('Media index run finished.', $display);
        $this->assertStringNotContainsString('Dry run', $display);
    }

    public function testDryRunAndAllFlagsAreForwarded(): void
    {
        $this->index->expects($this->once())
            ->method('run')
            ->with(true, true, false)
            ->willReturn(new MediaIndexRunSummary());

        $status = $this->tester->execute([
            '--dry-run' => true,
            '--all'     => true,
        ]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Dry run', $this->tester->getDisplay());
    }

    public function testSkipRenditionsFlagIsForwarded(): void
    {
        $this->index->expects($this->once())
            ->method('run')
            ->with(false, false, true)
            ->willReturn(new MediaIndexRunSummary());

        $status = $this->tester->execute([
            '--skip-renditions' => true,
        ]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Skipping rendition generation.', $this->tester->getDisplay());
    }
}
