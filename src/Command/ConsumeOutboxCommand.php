<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OutboxProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:outbox:consume',
    description: 'Traite un lot d evenements outbox et lance les controles automatiques Prestataire.'
)]
final class ConsumeOutboxCommand extends Command
{
    public function __construct(private readonly OutboxProcessor $processor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre maximal d evenements.', '50')
            ->addOption('max-attempts', null, InputOption::VALUE_REQUIRED, 'Tentatives avant echec definitif.', '5')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format: table ou json.', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $maxAttempts = filter_var($input->getOption('max-attempts'), FILTER_VALIDATE_INT);
        $format = strtolower(trim((string) $input->getOption('format')));

        if (!is_int($limit) || $limit < 1 || $limit > 1000) {
            $io->error('La limite doit etre comprise entre 1 et 1000.');

            return Command::INVALID;
        }
        if (!is_int($maxAttempts) || $maxAttempts < 1 || $maxAttempts > 20) {
            $io->error('Le nombre maximal de tentatives doit etre compris entre 1 et 20.');

            return Command::INVALID;
        }
        if (!in_array($format, ['table', 'json'], true)) {
            $io->error('Le format doit etre "table" ou "json".');

            return Command::INVALID;
        }

        try {
            $report = $this->processor->process($limit, $maxAttempts);
        } catch (\Throwable $exception) {
            $io->error(sprintf('Traitement outbox impossible: %s', $exception->getMessage()));

            return Command::FAILURE;
        }

        if ($format === 'json') {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $io->title('Traitement outbox');
            $io->definitionList(
                ['Traites' => $report['processed']],
                ['Publies' => $report['published']],
                ['A reessayer' => $report['retried']],
                ['En echec definitif' => $report['failed']],
            );
        }

        return $report['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
