<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests\Site;

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
use PHPUnit\Framework\TestCase;

/**
 * The pages of the catalog, built with index.json from the sources of every version.
 */
final class SiteBuilderTest extends TestCase
{
    use TemporaryCatalog;

    /** @var array{public: string, secret: string} */
    private array $keys;

    protected function setUp(): void
    {
        $this->createCatalog();
        $this->keys = Signature::keyPair();
        file_put_contents($this->root.'/catalog.pub', $this->keys['public']."\n");
        $this->addVersion('1.0.0');
        $this->addVersion('1.1.0');
    }

    protected function tearDown(): void
    {
        $this->removeCatalog();
    }

    public function testAPageShowsWhatTheCurrentVersionHolds(): void
    {
        file_put_contents($this->root.'/yanked.yaml', "acme/shop: [1.1.0]\n");
        $page = $this->build()['templates/acme/shop/index.html'];

        // 1.1.0 is yanked: an install without a version takes 1.0.0, and so does the page.
        self::assertStringContainsString('<li class="badge">1.0.0</li>', $page);
        self::assertStringContainsString('metadata:template:install acme/shop', $page);
        self::assertStringContainsString('Products, their categories and their tags.', $page);
        self::assertStringContainsString('<li class="badge">MIT</li>', $page);
        // Objects and fields, a relation naming its target by its plural label.
        self::assertStringContainsString('Products <code>product</code>', $page);
        self::assertStringContainsString('relation → Categories', $page);
        self::assertStringContainsString('Catalogue entry <code>catalogue</code>', $page);
        // The records of each seed, inline or in a CSV file.
        self::assertMatchesRegularExpression('~<td>Categories</td>\s*<td>2</td>\s*<td>kept in line with new versions</td>~', $page);
        self::assertMatchesRegularExpression('~<td>Products</td>\s*<td>2</td>\s*<td>yours once installed</td>~', $page);
        // Every version, newest first, the yanked one flagged, with its archive.
        self::assertMatchesRegularExpression('~1\.1\.0 <span class="badge warn">yanked</span>.*1\.0\.0~s', $page);
        self::assertStringContainsString('href="../../../archives/acme/shop-1.0.0.tar.gz"', $page);
    }

    public function testAPageLinksTheTemplatesItRequiresAndThoseRequiringIt(): void
    {
        $this->addFixture('places', '1.2.0');
        $this->addFixture('people', '1.0.0');
        $site = $this->build(['templates/acme/places/index.html', 'templates/acme/people/index.html']);

        $people = $site['templates/acme/people/index.html'];
        self::assertStringContainsString('Requires <a href="../../../templates/acme/places/">acme/places</a> <code>^1.0</code>: an install brings what is missing along.', $people);
        // In the list of versions too, the format left as it is.
        self::assertStringContainsString('<code>format metadata-driven/v1</code><br><a href="../../../templates/acme/places/">acme/places</a> <code>^1.0</code>', $people);
        self::assertStringContainsString('Required by <a href="../../../templates/acme/people/">acme/people</a>.', $site['templates/acme/places/index.html']);
        self::assertStringNotContainsString('Required by', $people);
    }

    public function testTheHomePageListsTheTemplatesAndHowToUseTheCatalog(): void
    {
        $site = $this->build();

        self::assertStringContainsString('href="templates/acme/shop/"', $site['index.html']);
        self::assertStringContainsString('TEMPLATE_CATALOG_URL=https://fedale.github.io/metadata-driven-templates/index.json', $site['index.html']);
        self::assertStringContainsString('TEMPLATE_CATALOG_KEY='.$this->keys['public'], $site['index.html']);

        self::assertSame(['templates' => [[
            'name' => 'acme/shop',
            'label' => 'Shop',
            'description' => 'Products, their categories and their tags.',
            'version' => '1.1.0',
            'license' => 'MIT',
            'verified' => false,
            'objects' => ['category', 'product', 'tag'],
            'url' => 'templates/acme/shop/',
        ]]], json_decode($site['search.json'], true));
    }

    public function testARemovedTemplateKeepsItsPageOnly(): void
    {
        file_put_contents($this->root.'/yanked.yaml', "acme/shop: all\n");
        $site = $this->build();

        self::assertStringNotContainsString('templates/acme/shop/', $site['index.html']);
        self::assertSame(['templates' => []], json_decode($site['search.json'], true));
        self::assertStringContainsString('removed from the catalog', $site['templates/acme/shop/index.html']);
        // Installable by its exact version only: the command names one.
        self::assertStringContainsString('metadata:template:install acme/shop@1.1.0', $site['templates/acme/shop/index.html']);
    }

    public function testAVerifiedVendorIsShown(): void
    {
        file_put_contents($this->root.'/vendors.yaml', "acme:\n  owners: [mario-rossi]\n  verified: true\n");
        $site = $this->build();

        self::assertStringContainsString('verified vendor', $site['templates/acme/shop/index.html']);
        self::assertStringContainsString('verified vendor', $site['index.html']);
        self::assertStringContainsString('"verified": true', $site['search.json']);
    }

    public function testWhatTheAuthorsWriteIsEscaped(): void
    {
        $manifest = $this->root.'/templates/acme/shop/1.1.0/template.yaml';
        file_put_contents($manifest, str_replace('description: Products, their categories and their tags.', 'description: \'<script>alert(1)</script>\'', (string) file_get_contents($manifest)));
        $site = $this->build();

        foreach (['index.html', 'templates/acme/shop/index.html'] as $file) {
            self::assertStringNotContainsString('<script>alert(1)</script>', $site[$file], $file);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $site[$file], $file);
        }
    }

    public function testTheReadmeIsRenderedWithoutHtmlNorUnsafeLinks(): void
    {
        file_put_contents($this->root.'/templates/acme/shop/1.1.0/README.md', <<<'MARKDOWN'
            # A shop

            Chairs and *tables*.

            <script>alert(1)</script>

            [Docs](https://example.com/docs) and [a trap](javascript:alert(1)).
            MARKDOWN);
        $page = $this->build()['templates/acme/shop/index.html'];

        self::assertStringContainsString('<h1>A shop</h1>', $page);
        self::assertStringContainsString('<em>tables</em>', $page);
        self::assertStringContainsString('<a href="https://example.com/docs">Docs</a>', $page);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        self::assertStringNotContainsString('javascript:', $page);
    }

    public function testTheSameSourcesGiveTheSamePages(): void
    {
        $first = $this->build();
        touch($this->root.'/templates/acme/shop/1.0.0/template.yaml', time() - 3600);

        self::assertSame($first, $this->build());
    }

    /**
     * @param list<string> $pages other pages to read, by path in the site
     *
     * @return array<string, string> the pages and search.json, by path in the site
     */
    private function build(array $pages = []): array
    {
        $output = $this->root.'/site-'.bin2hex(random_bytes(4));
        (new CatalogBuilder(
            new PackageDirectory(new DocumentCodec()),
            new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords()),
            new Archive(),
            new SiteBuilder(new SeedRecords()),
        ))->build($this->root, $output, $this->keys['secret']);

        $site = [];
        foreach (['index.html', 'search.json', 'templates/acme/shop/index.html', ...$pages] as $file) {
            self::assertFileExists($output.'/'.$file);
            $site[$file] = (string) file_get_contents($output.'/'.$file);
        }

        return $site;
    }
}
