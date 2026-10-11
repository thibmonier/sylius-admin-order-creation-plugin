<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Command;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tmonier\SyliusAdminOrderCreationPlugin\Purger\DraftPurgerInterface;

#[AsCommand(
    name: 'tmonier:admin-order:purge-drafts',
    description: 'Deletes the draft orders created in the back office and not modified for a while.',
)]
final class PurgeDraftsCommand extends Command
{
    public function __construct(
        private readonly DraftPurgerInterface $purger,
        private readonly ClockInterface $clock,
        private readonly int $defaultLifetimeDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete the drafts not modified for this number of days', (string) $this->defaultLifetimeDays)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the drafts that would be deleted')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = $input->getOption('days');
        if (!is_numeric($days) || (int) $days < 1 || (string) (int) $days !== (string) $days) {
            $io->error('The --days option must be a positive integer.');

            return Command::INVALID;
        }

        $threshold = $this->clock->now()->modify(sprintf('-%d days', (int) $days));

        if (true === $input->getOption('dry-run')) {
            $io->success(sprintf('%d draft order(s) not modified since %s would be deleted.', $this->purger->countExpired($threshold), $threshold->format('Y-m-d H:i')));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d draft order(s) not modified since %s deleted.', $this->purger->purge($threshold), $threshold->format('Y-m-d H:i')));

        return Command::SUCCESS;
    }
}
