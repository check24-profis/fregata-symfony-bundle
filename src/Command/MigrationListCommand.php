<?php

namespace Fregata\Command;

use Fregata\Helper\CommandHelper;
use Fregata\Migration\MigrationRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrationListCommand extends Command
{
    public function __construct(
        private readonly MigrationRegistry $migrationRegistry,
        private readonly CommandHelper $commandHelper
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->setName('fregata:migration:list')
            ->setDescription('List all registered migrations with additional information.')
            ->setHelp('List all registered migrations.')
            ->addOption(
                'with-migrators',
                'm',
                InputOption::VALUE_NONE,
                'Lists the migrators associated with each migration.'
            )
            ->addOption(
                'with-tasks',
                't',
                InputOption::VALUE_NONE,
                'Lists the before and after tasks associated with each migration.'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $migrations = $this->migrationRegistry->getAll();
        $io->title(sprintf('Registered migrations: %d', count($migrations)));

        foreach ($migrations as $name => $migration) {
            $io->writeln($name);

            if ($input->getOption('with-tasks')) {
                $this->commandHelper->printObjectTable($io, $migration->getBeforeTasks(), 'Before Task');
            }
            if ($input->getOption('with-migrators')) {
                $this->commandHelper->printObjectTable($io, $migration->getMigrators(), 'Migrator Name');
            }
            if ($input->getOption('with-tasks')) {
                $this->commandHelper->printObjectTable($io, $migration->getAfterTasks(), 'After Task');
            }
        }

        $io->newLine();
        return 0;
    }
}
