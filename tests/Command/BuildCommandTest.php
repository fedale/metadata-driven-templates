<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests\Command;

use Fedale\TemplateCatalog\CatalogBuilder;
use Fedale\TemplateCatalog\Command\BuildCommand;
use Fedale\TemplateCatalog\Site\SiteBuilder;
use Fedale\TemplateCatalog\Tests\Support\TemporaryCatalog;
use Fedale\TemplateFormat\Archive;
use Fedale\TemplateFormat\DocumentCodec;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\SchemaValidator;
use Fedale\TemplateFormat\SeedRecords;
use Fedale\TemplateFormat\Signature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BuildCommandTest extends TestCase
{
    use TemporaryCatalog;

    protected function setUp(): void
    {
        $this->createCatalog();
        $this->addVersion('1.0.0');
    }

    protected function tearDown(): void
    {
        putenv(BuildCommand::SECRET_KEY_VARIABLE);
        $this->removeCatalog();
    }

    public function testSignsWithTheKeyOfTheEnvironment(): void
    {
        $keys = Signature::keyPair();
        file_put_contents($this->root.'/catalog.pub', $keys['public']);
        putenv(BuildCommand::SECRET_KEY_VARIABLE.'='.$keys['secret']);

        $tester = $this->runBuild([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 version(s), signed.', $tester->getDisplay());
    }

    public function testIsAlwaysSignedUnlessToldOtherwise(): void
    {
        $tester = $this->runBuild([]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('CATALOG_SECRET_KEY is not set', $tester->getDisplay());

        $tester = $this->runBuild(['--unsigned' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 version(s), unsigned.', $tester->getDisplay());
    }

    public function testListsWhatStopsThePublication(): void
    {
        $this->addVersion('1.1.0', manifestVersion: '1.0.0');

        $tester = $this->runBuild(['--unsigned' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            ['templates/acme/shop/1.1.0: The manifest says acme/shop 1.0.0.', '1 problem(s): nothing was published.'],
            array_values(array_filter(explode("\n", $tester->getDisplay()))),
        );
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function runBuild(array $options): CommandTester
    {
        $tester = new CommandTester(new BuildCommand(new CatalogBuilder(
            new PackageDirectory(new DocumentCodec()),
            new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords()),
            new Archive(),
            new SiteBuilder(new SeedRecords()),
        )));
        $tester->execute($options + ['--repository' => $this->root, '--output' => $this->root.'/site-'.bin2hex(random_bytes(2))]);

        return $tester;
    }
}
