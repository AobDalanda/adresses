<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ProviderLegacyBackfillService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:provider:backfill',
    description: 'Importe les profils et dossiers Prestataire legacy dans le modele canonique.'
)]
final class BackfillProviderLegacyCommand extends Command
{
    public function __construct(private readonly ProviderLegacyBackfillService $backfill)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule le lot sans modifier les donnees.')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Nombre maximal de profils a importer.', '100')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format de sortie: table ou json.', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = strtolower(trim((string) $input->getOption('format')));
        $batchSize = filter_var($input->getOption('batch-size'), FILTER_VALIDATE_INT);

        if (!in_array($format, ['table', 'json'], true)) {
            $io->error('Le format doit etre "table" ou "json".');

            return Command::INVALID;
        }
        if (!is_int($batchSize) || $batchSize < 1 || $batchSize > 1000) {
            $io->error('La taille de lot doit etre comprise entre 1 et 1000.');

            return Command::INVALID;
        }

        try {
            $report = $this->backfill->backfill($batchSize, (bool) $input->getOption('dry-run'));
        } catch (\Throwable $exception) {
            $io->error(sprintf('Backfill impossible: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        if ($format === 'json') {
            $output->writeln((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return Command::SUCCESS;
        }

        $io->title($report['dryRun'] ? 'Simulation du backfill Prestataire' : 'Backfill Prestataire');
        $io->definitionList(
            ['Candidats du lot' => $report['candidates']],
            ['Profils importes' => $report['imported']],
            ['Profils eligibles restants' => $report['remaining']],
        );
        $io->section('Cas ignores');
        $io->table(
            ['Motif', 'Nombre'],
            array_map(
                static fn (string $reason, int $count): array => [$reason, $count],
                array_keys($report['skipped']),
                array_values($report['skipped']),
            ),
        );

        if ($report['dryRun']) {
            $io->note('Simulation uniquement: aucune donnee n a ete modifiee.');
        } else {
            $io->success(sprintf('%d profil(s) importe(s).', $report['imported']));
        }

        return Command::SUCCESS;
    }
}
