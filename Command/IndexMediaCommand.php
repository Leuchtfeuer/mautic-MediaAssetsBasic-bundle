<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Command;

use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\Contract\MediaIndexer;
use MauticPlugin\LeuchtfeuerMediaAssetsBasicBundle\DTO\MediaIndexRunSummary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'leuchtfeuer:media:index-media',
    description: 'Index media files and folders from disk into the database (reconcile / backfill).',
)]
final class IndexMediaCommand extends Command
{
    public function __construct(private readonly MediaIndexer $index)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Log changes without writing to the database')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Re-inspect every file on disk, not only new or changed ones');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $reindex  = (bool) $input->getOption('all');

        if ($dryRun) {
            $io->note('Dry run — no database writes.');
        }

        $summary = $this->index->run($reindex, $dryRun);
        $this->renderSummary($io, $summary);

        $io->success('Media index run finished.');

        return Command::SUCCESS;
    }

    private function renderSummary(SymfonyStyle $io, MediaIndexRunSummary $summary): void
    {
        $io->table(
            ['Metric', 'Count'],
            [
                ['Files indexed (new)', (string) $summary->filesIndexed],
                ['  of which duplicate checksum', (string) $summary->filesIndexedDuplicateChecksum],
                ['Files updated', (string) $summary->filesUpdated],
                ['Files soft-deleted (missing on disk)', (string) $summary->filesSoftDeleted],
                ['Files restored', (string) $summary->filesRestored],
                ['Folders indexed (new)', (string) $summary->foldersIndexed],
                ['Folders soft-deleted (missing on disk)', (string) $summary->foldersSoftDeleted],
                ['Folders restored', (string) $summary->foldersRestored],
                ['Skipped entries', (string) $summary->skipped],
            ],
        );
    }
}
