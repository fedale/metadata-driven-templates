<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Command;

use Fedale\TemplateCatalog\BuildFailed;
use Fedale\TemplateCatalog\CatalogBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'build', description: 'Build the published catalog: index.json and the archives, for GitHub Pages')]
final class BuildCommand extends Command
{
    /** The secret key comes from the environment: an argument would show in the process list. */
    public const string SECRET_KEY_VARIABLE = 'CATALOG_SECRET_KEY';

    public function __construct(private readonly CatalogBuilder $builder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'The directory to build the site into: new or empty')
            ->addOption('repository', null, InputOption::VALUE_REQUIRED, 'The checkout of the catalog', '.')
            ->addOption('unsigned', null, InputOption::VALUE_NONE, 'Build without signing, to look at it locally: never publish it')
            ->setHelp(<<<'HELP'
                Packs every version under templates/ into archives/<vendor>/<name>-<version>.tar.gz,
                and lists them in index.json, yanked versions flagged (yanked.yaml), each entry
                signed with the catalog's secret key, read from the CATALOG_SECRET_KEY environment
                variable and checked against catalog.pub.

                Nothing is written unless every version is valid.
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $site = $input->getOption('output');
        $repository = $input->getOption('repository');
        if (!\is_string($site) || '' === $site || !\is_string($repository)) {
            $output->writeln('<error>--output names the directory to build the site into.</error>');

            return Command::INVALID;
        }
        $secretKey = getenv(self::SECRET_KEY_VARIABLE);
        $secretKey = false === $secretKey || '' === trim($secretKey) ? null : $secretKey;
        $unsigned = true === $input->getOption('unsigned');
        if (null === $secretKey && !$unsigned) {
            $output->writeln(\sprintf('<error>%s is not set: the catalog is always signed. Build with --unsigned to look at it locally.</error>', self::SECRET_KEY_VARIABLE));

            return Command::FAILURE;
        }
        if (null !== $secretKey && $unsigned) {
            $output->writeln(\sprintf('<error>--unsigned, with %s set: pick one.</error>', self::SECRET_KEY_VARIABLE));

            return Command::INVALID;
        }

        try {
            $entries = $this->builder->build($repository, $site, $secretKey);
        } catch (BuildFailed $e) {
            foreach ($e->problems as $problem) {
                $output->writeln($problem, OutputInterface::OUTPUT_RAW);
            }
            $output->writeln(\sprintf('<error>%d problem(s): nothing was published.</error>', \count($e->problems)));

            return Command::FAILURE;
        }

        foreach ($entries as $entry) {
            $output->writeln(\sprintf('%s %s%s', $entry->name, $entry->version, $entry->yanked ? ' (yanked)' : ''));
        }
        $output->writeln(\sprintf('<info>%d version(s), %s.</info>', \count($entries), $unsigned ? 'unsigned' : 'signed'));

        return Command::SUCCESS;
    }
}
