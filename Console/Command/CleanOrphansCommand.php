<?php
declare(strict_types=1);

namespace Shubham\MediaOrphanFinder\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Shubham\MediaOrphanFinder\Model\OrphanScanner;

class CleanOrphansCommand extends Command
{
    private const OPTION_QUARANTINE = 'quarantine';
    private const OPTION_LIST = 'list';

    public function __construct(
        private readonly OrphanScanner $scanner,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('media:orphan:clean')
            ->setDescription('Audit and safely quarantine orphaned product media files')
            ->addOption(
                self::OPTION_QUARANTINE,
                null,
                InputOption::VALUE_NONE,
                'Move orphaned files to var/orphan_media_quarantine (defaults to dry-run)'
            )
            ->addOption(
                self::OPTION_LIST,
                null,
                InputOption::VALUE_NONE,
                'Print every orphaned file path found'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isQuarantine = (bool)$input->getOption(self::OPTION_QUARANTINE);
        $showList = (bool)$input->getOption(self::OPTION_LIST);
        $output->writeln('<info>Scanning Magento database for active product media...</info>');
        $activeMap = $this->scanner->getActiveMediaMap();
        $output->writeln(sprintf('Active DB image paths found: <comment>%d</comment>', count($activeMap)));
        $output->writeln('<info>Scanning pub/media/catalog/product filesystem...</info>');
        $result = $this->scanner->scan($activeMap);
        $orphanCount = count($result['orphans']);
        $output->writeln('');
        $output->writeln('--- Audit Summary ---');
        $output->writeln(sprintf('Total Images Scanned : %d', $result['total_scanned']));
        $output->writeln(sprintf('Orphaned Images      : <comment>%d</comment>', $orphanCount));
        $output->writeln(sprintf('Reclaimable Space    : <info>%s</info>', $this->formatBytes($result['total_bytes'])));
        $output->writeln('');
        if ($orphanCount === 0) {
            $output->writeln('<info>No orphaned product images found!</info>');
            return Command::SUCCESS;
        }
        if ($showList) {
            foreach ($result['orphans'] as $orphan) {
                $output->writeln(sprintf(' - %s (%s)', $orphan['relative'], $this->formatBytes($orphan['size'])));
            }
            $output->writeln('');
        }
        if ($isQuarantine) {
            $output->writeln('<comment>Moving orphaned files to quarantine...</comment>');
            $quarantineDir = $this->scanner->quarantine($result['orphans']);
            $output->writeln(sprintf('<info>Done! Moved %d files to: %s</info>', $orphanCount, $quarantineDir));
        } else {
            $output->writeln('<comment>Dry-run mode: No files were moved.</comment>');
            $output->writeln('Run <info>bin/magento media:orphan:clean --quarantine</info> to move them safely.');
        }
        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = (int)floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }
}
