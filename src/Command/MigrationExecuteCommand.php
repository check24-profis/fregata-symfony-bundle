<?php

namespace Fregata\Command;

use Fregata\Migration\MigrationRegistry;
use Fregata\Migration\Migrator\MigratorInterface;
use Fregata\Migration\TaskInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class MigrationExecuteCommand extends Command
{
    /**
     * \33[2K erases the current line
     * \r moves the cursor the beginning of the line
     */
    private const LINE_ERASER = "\33[2K\r";

    public function __construct(
        private readonly MigrationRegistry $migrationRegistry
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->setName('fregata:migration:execute')
            ->setDescription('Execute a migration.')
            ->setHelp('Execute a migration.')
            ->addArgument(
                'migration',
                InputArgument::REQUIRED,
                'The name of the migration.'
            );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isInteractive = $input->hasOption('no-interaction') && false === $input->getOption('no-interaction');
        $io = new SymfonyStyle($input, $output);

        $migrationName = $input->getArgument('migration');
        $migration = $this->migrationRegistry->get($migrationName);

        if (null === $migration) {
            $io->error(sprintf('No migration registered with the name "%s".', $migrationName));
            return 1;
        }

        // Confirm execution
        if ($isInteractive) {
            $confirmationMessage = sprintf('Confirm execution of the "%s" migration ?', $migrationName);
            $confirmation = $io->confirm($confirmationMessage, false);

            if (false === $confirmation) {
                $io->error('Aborting.');
                return 1;
            }
        }

        // Starting title
        $migrators = $migration->getMigrators();
        $io->success(sprintf('Starting "%s" migration: %s migrators', $migrationName, count($migrators)));

        // Run before tasks
        if (0 !== count($migration->getBeforeTasks())) {
            $io->title(sprintf('Before tasks: %d', count($migration->getBeforeTasks())));

            foreach ($migration->getBeforeTasks() as $task) {
                $this->runTask($io, $task);
            }
            $io->newLine();
        }

        // Run migrators
        $io->title(sprintf('Migrators: %d', count($migrators)));

        foreach ($migrators as $key => $migrator) {
            $puller = $migrator->getPuller();
            $totalItems = null === $puller ? null : $puller->count();

            if (!$output instanceof ConsoleOutputInterface) {
                $this->runMigratorWithCustomProgress($io, $migrator, $totalItems, $key);
            } elseif (null !== $totalItems) {
                $this->runMigratorWithProgressBar($io, $migrator, $totalItems, $key);
            } else {
                $this->runMigratorWithoutProgressBar($io, $output, $migrator, $key);
            }
            $io->newLine();
        }

        // Run before tasks
        if (0 !== count($migration->getAfterTasks())) {
            $io->title(sprintf('After tasks: %d', count($migration->getAfterTasks())));

            foreach ($migration->getAfterTasks() as $task) {
                $this->runTask($io, $task);
            }
            $io->newLine();
        }

        $io->success('Migrated successfully !');
        $io->newLine();
        return 0;
    }

    /**
     * Execute a migrator with a custom progress line
     */
    private function runMigratorWithCustomProgress(
        SymfonyStyle $io,
        MigratorInterface $migrator,
        ?int $itemCount,
        int $migratorIndex
    ): void {
        $io->title(sprintf('%d - Executing "%s" :', $migratorIndex, get_class($migrator)));
        $totalPushCount = 0;

        foreach ($migrator->getExecutor()->execute($migrator->getPuller(), $migrator->getPusher()) as $pushedItemCount) {
            $totalPushCount += $pushedItemCount;
            $io->write(sprintf('%s %d', self::LINE_ERASER, $totalPushCount));

            if (null !== $itemCount) {
                $io->write(sprintf(' / %d', $itemCount));
            }
        }
        $io->newLine();
    }

    /**
     * Execute a migrator with a progress bar
     */
    private function runMigratorWithProgressBar(
        SymfonyStyle $io,
        MigratorInterface $migrator,
        int $itemCount,
        int $migratorIndex
    ): void {
        $io->title(sprintf('%d - Executing "%s" [%d items] :', $migratorIndex, get_class($migrator), $itemCount));
        $progressBar = $io->createProgressBar($itemCount);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% [Remaining: %formattedRemaining%]');
        $progressBar->setEmptyBarCharacter('░');
        $progressBar->setProgressCharacter('');
        $progressBar->setBarCharacter('▓');

        $progressBar->setMessage('-', 'formattedRemaining');

        foreach ($migrator->getExecutor()->execute($migrator->getPuller(), $migrator->getPusher()) as $pushedItemCount) {
            $progressBar->advance($pushedItemCount);

            $remaining = $progressBar->getRemaining();
            $remainingMinutes = floor($remaining / 60);
            $remainingSeconds = $remaining - ($remainingMinutes * 60);
            $formattedRemaining = sprintf('%s:%s min',
                str_pad($remainingMinutes, 2, '0', STR_PAD_LEFT),
                str_pad($remainingSeconds, 2, '0', STR_PAD_LEFT)
            );
            $progressBar->setMessage($formattedRemaining, 'formattedRemaining');
        }

        $progressBar->finish();
        $io->newLine(2);
    }

    /**
     * Execute a migrator without progress bar
     */
    private function runMigratorWithoutProgressBar(
        SymfonyStyle $io,
        ConsoleOutputInterface $output,
        MigratorInterface $migrator,
        int $migratorIndex
    ): void {
        $io->title(sprintf('%d - Executing "%s" :', $migratorIndex, get_class($migrator)));

        $section = $output->section();
        $totalPushCount = 0;

        $section->writeln(sprintf('Migrated items: %d', $totalPushCount));

        foreach ($migrator->getExecutor()->execute($migrator->getPuller(), $migrator->getPusher()) as $pushedItemCount) {
            $totalPushCount += $pushedItemCount;
            $section->overwrite(sprintf('Migrated items: %d', $totalPushCount));
        }
    }

    /**
     * Execute a before / after task
     */
    private function runTask(SymfonyStyle $io, TaskInterface $task): void
    {
        $io->write(sprintf(' %s : ...', get_class($task)));
        $result = $task->execute();

        $io->write(sprintf('%s %s : %s', self::LINE_ERASER, get_class($task), $result ?? 'OK'));
        $io->newLine();
    }
}
