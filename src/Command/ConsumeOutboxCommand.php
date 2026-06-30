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
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Format: table ou json.', 'table')
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Reste actif et traite continuellement l outbox.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Pause en secondes entre deux lots en mode watch.', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        $maxAttempts = filter_var($input->getOption('max-attempts'), FILTER_VALIDATE_INT);
        $format = strtolower(trim((string) $input->getOption('format')));
        $watch = (bool) $input->getOption('watch');
        $sleep = filter_var($input->getOption('sleep'), FILTER_VALIDATE_FLOAT);

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
        if ($sleep === false || $sleep < 0.1 || $sleep > 60) {
            $io->error('La pause doit etre comprise entre 0.1 et 60 secondes.');

            return Command::INVALID;
        }

        do {
            try {
                $report = $this->processor->process($limit, $maxAttempts);
            } catch (\Throwable $exception) {
                $io->error(sprintf('Traitement outbox impossible: %s', $exception->getMessage()));
                if (!$watch) {
                    return Command::FAILURE;
                }
                usleep((int) ($sleep * 1_000_000));
                continue;
            }

            if (!$watch || $report['processed'] > 0) {
                if ($format === 'json') {
                    $output->writeln((string) json_encode($report, JSON_THROW_ON_ERROR));
                } else {
                    $io->title('Traitement outbox');
                    $io->definitionList(
                        ['Traites' => $report['processed']],
                        ['Publies' => $report['published']],
                        ['A reessayer' => $report['retried']],
                        ['En echec definitif' => $report['failed']],
                    );
                }
            }

            if ($watch) {
                usleep((int) ($sleep * 1_000_000));
            }
        } while ($watch);

        return $report['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
