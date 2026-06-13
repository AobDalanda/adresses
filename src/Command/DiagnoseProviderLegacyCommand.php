<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ProviderLegacyDiagnosticService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:provider:diagnose',
    description: 'Diagnostique les incoherences entre provider_profile et driver_application sans modifier les donnees.'
)]
final class DiagnoseProviderLegacyCommand extends Command
{
    public function __construct(private readonly ProviderLegacyDiagnosticService $diagnostic)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Format de sortie: table ou json.',
                'table'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Nombre maximal d exemples par categorie.',
                '20'
            )
            ->addOption(
                'fail-on-issues',
                null,
                InputOption::VALUE_NONE,
                'Retourne un code d echec lorsque des incoherences sont detectees.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = strtolower(trim((string) $input->getOption('format')));
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);

        if (!in_array($format, ['table', 'json'], true)) {
            $io->error('Le format doit etre "table" ou "json".');

            return Command::INVALID;
        }

        if (!is_int($limit) || $limit < 1 || $limit > 1000) {
            $io->error('La limite doit etre comprise entre 1 et 1000.');

            return Command::INVALID;
        }

        try {
            $report = $this->diagnostic->diagnose($limit);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Diagnostic impossible: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        if ($format === 'json') {
            $output->writeln((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
        } else {
            $this->renderTable($io, $report);
        }

        if ((bool) $input->getOption('fail-on-issues') && $report['issueCount'] > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array{
     *     totals: array{profiles: int, applications: int},
     *     issueCount: int,
     *     byType: array<string, int>,
     *     issues: list<array{
     *         type: string,
     *         severity: string,
     *         description: string,
     *         context: array<string, mixed>
     *     }>
     * } $report
     */
    private function renderTable(SymfonyStyle $io, array $report): void
    {
        $io->title('Diagnostic legacy Prestataire');
        $io->definitionList(
            ['Profils prestataires' => $report['totals']['profiles']],
            ['Dossiers driver' => $report['totals']['applications']],
            ['Incoherences detectees' => $report['issueCount']]
        );

        $summaryRows = [];
        foreach ($report['byType'] as $type => $count) {
            $summaryRows[] = [$type, $count];
        }
        $io->section('Synthese');
        $io->table(['Categorie', 'Nombre'], $summaryRows);

        if ($report['issues'] === []) {
            $io->success('Aucune incoherence legacy detectee.');

            return;
        }

        $detailRows = [];
        foreach ($report['issues'] as $issue) {
            $detailRows[] = [
                $issue['severity'],
                $issue['type'],
                $issue['description'],
                (string) json_encode(
                    $issue['context'],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
            ];
        }

        $io->section('Exemples');
        $io->table(['Severite', 'Categorie', 'Description', 'Contexte'], $detailRows);
        $io->warning('Rapport en lecture seule: aucune donnee n a ete modifiee.');
    }
}
