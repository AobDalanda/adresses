<?php

namespace App\Command;

use App\Service\Subscription\SubscriptionManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:subscriptions:expire')]
final class ExpireSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $expired = $this->subscriptions->expireSubscriptions();
        $this->entityManager->flush();

        $output->writeln(sprintf('%d abonnement(s) expire(s).', count($expired)));

        return Command::SUCCESS;
    }
}
