<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests\Command;

use Fedale\TemplateCatalog\Command\CheckCommand;
use Fedale\TemplateCatalog\PullRequestCheck;
use Fedale\TemplateCatalog\Tests\Support\TemporaryCatalog;
use Fedale\TemplateFormat\DocumentCodec;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\SchemaValidator;
use Fedale\TemplateFormat\SeedRecords;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * The check as the Action runs it: a git checkout of a pull request, compared with its base.
 */
final class CheckCommandTest extends TestCase
{
    use TemporaryCatalog;

    protected function setUp(): void
    {
        $this->createCatalog();
        $this->git('init', '-q', '-b', 'main');
        file_put_contents($this->root.'/vendors.yaml', "acme:\n  owners: [mario-rossi]\n");
        $this->addVersion('1.0.0');
        $this->commit('Publish acme/shop 1.0.0');
        $this->git('checkout', '-q', '-b', 'shop-1.1.0');
    }

    protected function tearDown(): void
    {
        $this->removeCatalog();
    }

    public function testAPullRequestReadyForReview(): void
    {
        $this->addVersion('1.1.0');
        $this->commit('Publish acme/shop 1.1.0');

        $tester = $this->runCheck(['--author' => 'mario-rossi']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Ready for review.', $tester->getDisplay());
    }

    public function testAPullRequestWithProblems(): void
    {
        $this->addVersion('1.1.0');
        file_put_contents($this->root.'/templates/acme/shop/1.0.0/README.md', '# Shop');
        $this->commit('Publish acme/shop 1.1.0, touch 1.0.0');

        $tester = $this->runCheck(['--author' => 'anna']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([
            'templates/acme/shop/1.0.0/README.md: A published version never changes: publish a new version instead.',
            'templates/acme/shop/1.1.0: anna is not an owner of the vendor "acme" (vendors.yaml).',
            '2 problem(s): fix them and push again.',
        ], array_values(array_filter(explode("\n", $tester->getDisplay()))));
    }

    public function testTheVendorsAreReadFromTheBase(): void
    {
        // The pull request makes its author an owner: that change waits for a maintainer.
        file_put_contents($this->root.'/vendors.yaml', "acme:\n  owners: [mario-rossi, anna]\n");
        $this->addVersion('1.1.0');
        $this->commit('Make anna an owner, publish acme/shop 1.1.0');

        $tester = $this->runCheck(['--author' => 'anna']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('anna is not an owner of the vendor "acme"', $tester->getDisplay());
    }

    public function testAnUnknownBase(): void
    {
        $tester = $this->runCheck(['--base' => 'origin/main']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('git ls-tree failed', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $options
     */
    private function runCheck(array $options): CommandTester
    {
        $tester = new CommandTester(new CheckCommand(new PullRequestCheck(
            new PackageDirectory(new DocumentCodec()),
            new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords()),
        )));
        $tester->execute($options + ['--base' => 'main', '--repository' => $this->root]);

        return $tester;
    }

    private function commit(string $message): void
    {
        $this->git('add', '-A');
        $this->git('-c', 'user.name=Catalog test', '-c', 'user.email=catalog@example.com', 'commit', '-q', '-m', $message);
    }

    private function git(string ...$arguments): void
    {
        (new Process(['git', ...$arguments], $this->root))->mustRun();
    }
}
