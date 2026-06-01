<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:subscription-plans:seed')]
final class SeedSubscriptionPlansCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sql = file_get_contents(__DIR__ . '/../../fixtures/subscription_plans.sql');
        if (!is_string($sql) || trim($sql) === '') {
            $output->writeln('Fixture SQL introuvable.');

            return Command::FAILURE;
        }

        $this->connection->executeStatement($sql);
        $output->writeln('Plans d’abonnement seedes.');

        return Command::SUCCESS;
    }
}
