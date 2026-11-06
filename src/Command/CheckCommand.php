<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Command;

use Fedale\TemplateCatalog\Git;
use Fedale\TemplateCatalog\PullRequestCheck;
use Fedale\TemplateCatalog\Vendors;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'check', description: 'Check what a pull request adds to the catalog, before a maintainer reviews it')]
final class CheckCommand extends Command
{
    public function __construct(private readonly PullRequestCheck $check)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('base', null, InputOption::VALUE_REQUIRED, 'The branch the pull request goes into, e.g. origin/main')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'The checkout of the pull request', '.')
            ->addOption('author', null, InputOption::VALUE_REQUIRED, 'The GitHub login of its author: they must own the vendors it publishes under')
            ->setHelp(<<<'HELP'
                Checks every version directory the pull request adds under templates/: a valid
                package, matching its path, higher than the listed versions, at most 20 MB, under
                a vendor of vendors.yaml (as the base branch lists it) that the author owns.
                Published versions may not change.

                The GitHub Action runs it from the base branch, so that a pull request cannot
                change the checks it is checked by.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = $input->getOption('base');
        $repository = $input->getOption('repository');
        $author = $input->getOption('author');
        if (!\is_string($base) || '' === $base || !\is_string($repository)) {
            $output->writeln('<error>--base names the branch the pull request goes into.</error>');

            return Command::INVALID;
        }

        try {
            $git = new Git($repository);
            $vendors = Vendors::fromYaml($git->show($base, 'vendors.yaml') ?? '');
            $problems = $this->check->check($repository, $git->changesSince($base), $vendors, \is_string($author) && '' !== $author ? $author : null, $git->versionDirectories($base));
        } catch (\RuntimeException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        if ([] === $problems) {
            $output->writeln('<info>Ready for review.</info>');

            return Command::SUCCESS;
        }

        foreach ($problems as $problem) {
            $output->writeln($problem, OutputInterface::OUTPUT_RAW);
        }
        $output->writeln(\sprintf('<error>%d problem(s): fix them and push again.</error>', \count($problems)));

        return Command::FAILURE;
    }
}
