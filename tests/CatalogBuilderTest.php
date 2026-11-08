<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests;

use Fedale\TemplateCatalog\BuildFailed;
use Fedale\TemplateCatalog\CatalogBuilder;
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
use Fedale\TemplateFormat\TemplateIndex;
use PHPUnit\Framework\TestCase;

final class CatalogBuilderTest extends TestCase
{
    use TemporaryCatalog;

    private const string ADDRESS = 'https://fedale.github.io/metadata-driven-templates/index.json';

    /** @var array{public: string, secret: string} */
    private array $keys;

    protected function setUp(): void
    {
        $this->createCatalog();
        $this->keys = Signature::keyPair();
        file_put_contents($this->root.'/catalog.pub', $this->keys['public']."\n");
        $this->addVersion('1.0.0');
        $this->addVersion('1.1.0');
        file_put_contents($this->root.'/yanked.yaml', "acme/shop: [1.0.0]\n");
    }

    protected function tearDown(): void
    {
        $this->removeCatalog();
    }

    public function testBuildsASignedIndexOfEveryVersion(): void
    {
        $entries = $this->builder()->build($this->root, $this->root.'/site', $this->keys['secret']);

        self::assertSame([['acme/shop', '1.0.0', true], ['acme/shop', '1.1.0', false]], array_map(static fn ($entry): array => [$entry->name, $entry->version, $entry->yanked], $entries));
        $index = TemplateIndex::fromJson((string) file_get_contents($this->root.'/site/index.json'), self::ADDRESS);
        self::assertSame('1.1.0', $index->latest('acme/shop')?->version);
        foreach ($index->versions('acme/shop') as $entry) {
            // The address metadata-driven downloads from: relative to the index.
            self::assertSame('https://fedale.github.io/metadata-driven-templates/archives/acme/shop-'.$entry->version.'.tar.gz', $entry->url);
            self::assertSame(hash_file('sha256', $this->root.'/site/archives/acme/shop-'.$entry->version.'.tar.gz'), $entry->sha256);
            self::assertSame(['format' => 'metadata-driven/v1'], $entry->requires);
            self::assertTrue(Signature::verify($entry, $this->keys['public']));
        }
        self::assertTrue($index->find('acme/shop', '1.0.0')?->yanked);
        self::assertSame($this->keys['public']."\n", file_get_contents($this->root.'/site/catalog.pub'));
    }

    public function testTheSameSourcesGiveTheSameSite(): void
    {
        $this->builder()->build($this->root, $this->root.'/first', $this->keys['secret']);
        touch($this->root.'/templates/acme/shop/1.0.0/template.yaml', time() - 3600);
        $this->builder()->build($this->root, $this->root.'/second', $this->keys['secret']);

        self::assertFileEquals($this->root.'/first/index.json', $this->root.'/second/index.json');
        self::assertFileEquals($this->root.'/first/archives/acme/shop-1.0.0.tar.gz', $this->root.'/second/archives/acme/shop-1.0.0.tar.gz');
    }

    public function testBuildsUnsignedToLookAtLocally(): void
    {
        $entries = $this->builder()->build($this->root, $this->root.'/site', null);

        self::assertNull($entries[0]->signature);
        self::assertFileDoesNotExist($this->root.'/site/catalog.pub');
    }

    public function testRefusesASecretKeyThatDoesNotMatchThePublicOne(): void
    {
        $this->assertFails(['The secret key does not match catalog.pub.'], Signature::keyPair()['secret']);
    }

    public function testNeedsThePublicKeyToSign(): void
    {
        file_put_contents($this->root.'/catalog.pub', "not a key\n");

        $this->assertFails(['catalog.pub: The catalog\'s public key is missing or invalid.'], $this->keys['secret']);
    }

    public function testPublishesNothingWhenAVersionIsInvalid(): void
    {
        $this->addVersion('1.2.0', manifestVersion: '1.1.0');

        $this->assertFails(['templates/acme/shop/1.2.0: The manifest says acme/shop 1.1.0.'], $this->keys['secret']);
        self::assertDirectoryDoesNotExist($this->root.'/site');
    }

    public function testYanksPublishedVersionsOnly(): void
    {
        file_put_contents($this->root.'/yanked.yaml', "acme/shop: [2.0.0]\n");

        $this->assertFails(['yanked.yaml: acme/shop 2.0.0 is not published.'], $this->keys['secret']);
    }

    public function testRefusesALinkedVersion(): void
    {
        symlink($this->root.'/templates/acme/shop/1.0.0', $this->root.'/templates/acme/shop/1.2.0');

        $this->assertFails(['templates/acme/shop/1.2.0: Not a directory: symbolic links are not allowed.'], $this->keys['secret']);
    }

    public function testBuildsIntoANewOrEmptyDirectory(): void
    {
        mkdir($this->root.'/site');
        file_put_contents($this->root.'/site/index.html', '<p>Old site</p>');

        $this->assertFails([$this->root.'/site: The site is built into a new or empty directory.'], $this->keys['secret']);
    }

    /**
     * @param list<string> $problems
     */
    private function assertFails(array $problems, ?string $secretKey): void
    {
        try {
            $this->builder()->build($this->root, $this->root.'/site', $secretKey);
            self::fail('The build must fail.');
        } catch (BuildFailed $e) {
            self::assertSame($problems, $e->problems);
        }
    }

    private function builder(): CatalogBuilder
    {
        return new CatalogBuilder(
            new PackageDirectory(new DocumentCodec()),
            new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords()),
            new Archive(),
            new SiteBuilder(new SeedRecords()),
        );
    }
}
