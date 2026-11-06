<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests;

use Fedale\TemplateCatalog\ChangedFile;
use Fedale\TemplateCatalog\PullRequestCheck;
use Fedale\TemplateCatalog\Tests\Support\TemporaryCatalog;
use Fedale\TemplateCatalog\Vendors;
use Fedale\TemplateFormat\DocumentCodec;
use Fedale\TemplateFormat\FieldTypes\FieldTypeCatalog;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\SchemaValidator;
use Fedale\TemplateFormat\SeedRecords;
use PHPUnit\Framework\TestCase;

final class PullRequestCheckTest extends TestCase
{
    use TemporaryCatalog;

    /** @var list<string> the version directories the base branch has */
    private array $published = [];

    protected function setUp(): void
    {
        $this->createCatalog();
    }

    protected function tearDown(): void
    {
        $this->removeCatalog();
    }

    public function testAcceptsANewVersionByAnOwner(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        $added = $this->addVersion('1.1.0');

        self::assertSame([], $this->check($this->added($added), author: 'Mario-Rossi'));
        // Run locally by a maintainer: no author, no ownership check.
        self::assertSame([], $this->check($this->added($added), author: null));
    }

    public function testAcceptsTheFirstVersionsOfATemplateTogether(): void
    {
        $changes = [...$this->added($this->addVersion('1.0.0')), ...$this->added($this->addVersion('1.1.0'))];

        self::assertSame([], $this->check($changes));
    }

    public function testIgnoresWhatIsNotATemplate(): void
    {
        self::assertSame([], $this->check([new ChangedFile('M', 'vendors.yaml'), new ChangedFile('A', 'README.md')]));
    }

    public function testAPublishedVersionNeverChanges(): void
    {
        $this->published[] = $this->addVersion('1.0.0');

        self::assertSame([
            'templates/acme/shop/1.0.0/objects/tag.yaml: A published version never changes: publish a new version instead.',
            'templates/acme/shop/1.0.0/files/products/oslo.png: A published version never changes: publish a new version instead.',
        ], $this->check([
            new ChangedFile('M', 'templates/acme/shop/1.0.0/objects/tag.yaml'),
            new ChangedFile('D', 'templates/acme/shop/1.0.0/files/products/oslo.png'),
        ]));
        // Adding a file to a published version changes it too.
        self::assertSame(
            ['templates/acme/shop/1.0.0/README.md: A published version never changes: publish a new version instead.'],
            $this->check([new ChangedFile('A', 'templates/acme/shop/1.0.0/README.md')]),
        );
    }

    public function testAFileOutsideAVersionDirectory(): void
    {
        self::assertSame(
            ['templates/acme/shop/README.md: A template\'s files go in templates/<vendor>/<name>/<version>/.'],
            $this->check([new ChangedFile('A', 'templates/acme/shop/README.md')]),
        );
    }

    public function testTheAuthorMustOwnTheVendor(): void
    {
        $globex = $this->addVersion('1.0.0', 'globex/shop');
        $acme = $this->addVersion('1.0.0');

        self::assertSame([
            'templates/acme/shop/1.0.0: anna is not an owner of the vendor "acme" (vendors.yaml).',
            'templates/globex/shop/1.0.0: The vendor "globex" is not registered: add it to vendors.yaml in a pull request of its own first.',
        ], array_values(array_filter(
            $this->check([...$this->added($globex), ...$this->added($acme)], author: 'anna'),
            // globex/shop's documents keep the acme ids of the example: only the vendor problems matter here.
            static fn (string $problem): bool => str_ends_with($problem, '(vendors.yaml).') || str_ends_with($problem, 'first.'),
        )));
    }

    public function testTheNewVersionMustBeHigher(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        $this->published[] = $this->addVersion('1.2.0');

        self::assertSame(
            ['templates/acme/shop/1.1.0: acme/shop 1.2.0 is already listed: the new version must be higher.'],
            $this->check($this->added($this->addVersion('1.1.0'))),
        );
    }

    public function testTheManifestMustMatchItsDirectory(): void
    {
        $this->published[] = $this->addVersion('1.0.0');

        self::assertSame(
            ['templates/acme/shop/1.1.0: The manifest says acme/shop 1.0.0: the directory must be templates/acme/shop/1.0.0/.'],
            $this->check($this->added($this->addVersion('1.1.0', manifestVersion: '1.0.0'))),
        );
    }

    public function testNamesAndVersionsFollowTheFormat(): void
    {
        self::assertSame(
            ['templates/acme/Shop/1.0.0: "acme/Shop" is not a template name (vendor/name, lowercase letters, digits and hyphens).'],
            $this->check([new ChangedFile('A', 'templates/acme/Shop/1.0.0/template.yaml')]),
        );
        self::assertSame(
            ['templates/acme/shop/v1: "v1" is not a version (major.minor.patch, e.g. 1.0.0).'],
            $this->check([new ChangedFile('A', 'templates/acme/shop/v1/template.yaml')]),
        );
    }

    public function testReportsTheProblemsOfThePackageWhereTheyAre(): void
    {
        $added = $this->addVersion('1.0.0');
        file_put_contents($this->root.'/'.$added.'/objects/tag.yaml', str_replace('plural:', 'plurals:', (string) file_get_contents($this->root.'/'.$added.'/objects/tag.yaml')));

        $problems = $this->check($this->added($added));

        self::assertNotSame([], array_filter($problems, static fn (string $problem): bool => str_starts_with($problem, 'templates/acme/shop/1.0.0/objects/tag.yaml: spec')));
        foreach ($problems as $problem) {
            self::assertStringStartsWith('templates/acme/shop/1.0.0/', $problem);
        }
    }

    public function testAMissingManifest(): void
    {
        mkdir($this->root.'/templates/acme/shop/1.0.0', 0o777, true);
        file_put_contents($this->root.'/templates/acme/shop/1.0.0/README.md', '# Shop');

        self::assertSame(
            ['templates/acme/shop/1.0.0/template.yaml: No manifest in "'.$this->root.'/templates/acme/shop/1.0.0".'],
            $this->check([new ChangedFile('A', 'templates/acme/shop/1.0.0/README.md')]),
        );
    }

    public function testReadsNothingThroughASymbolicLink(): void
    {
        $added = $this->addVersion('1.0.0');
        // A manifest pointing at a file of the runner: read, it would show up in a problem.
        rename($this->root.'/'.$added.'/template.yaml', $this->root.'/manifest.yaml');
        symlink($this->root.'/manifest.yaml', $this->root.'/'.$added.'/template.yaml');
        file_put_contents($this->root.'/manifest.yaml', 'runner secret: [');

        $problems = $this->check($this->added($added));

        self::assertSame(['templates/acme/shop/1.0.0/template.yaml: Symbolic links are not allowed.'], $problems);
    }

    public function testRefusesALinkedDirectoryAboveTheVersion(): void
    {
        $this->addVersion('1.0.0', 'globex/shop');
        symlink($this->root.'/templates/globex', $this->root.'/templates/acme');

        self::assertSame(
            ['templates/acme: Not a directory: symbolic links are not allowed.'],
            $this->check([new ChangedFile('A', 'templates/acme/shop/1.0.0/template.yaml')]),
        );
    }

    public function testRefusesAHeavyVersion(): void
    {
        $added = $this->addVersion('1.0.0');
        // A sparse file: 21 MB as far as its size goes, nothing on the disk.
        $heavy = fopen($this->root.'/'.$added.'/files/catalogue.pdf', 'w');
        self::assertIsResource($heavy);
        ftruncate($heavy, 21_000_000);
        fclose($heavy);

        self::assertContains('templates/acme/shop/1.0.0: 21.0 MB: a version may weigh 20 MB at most, files included.', $this->check($this->added($added)));
    }

    /**
     * @param list<ChangedFile> $changes
     *
     * @return list<string>
     */
    private function check(array $changes, ?string $author = 'mario-rossi'): array
    {
        $check = new PullRequestCheck(
            new PackageDirectory(new DocumentCodec()),
            new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords()),
        );

        return $check->check($this->root, $changes, Vendors::fromYaml("acme:\n  owners: [mario-rossi]\n"), $author, $this->published);
    }

    /**
     * @return list<ChangedFile>
     */
    private function added(string $directory): array
    {
        return array_map(static fn (string $path): ChangedFile => new ChangedFile('A', $path), $this->filesOf($directory));
    }
}
