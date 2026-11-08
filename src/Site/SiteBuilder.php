<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Site;

use Fedale\TemplateCatalog\BuildFailed;
use Fedale\TemplateCatalog\Vendors;
use Fedale\TemplateFormat\IndexEntry;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\SeedRecords;
use Twig\Environment;
use Twig\Error\Error as TwigError;
use Twig\Loader\FilesystemLoader;

/**
 * The pages of the catalog, beside index.json: the home page (every template, searched in the
 * browser), a page per template, and search.json, the same list for tools.
 *
 * Every link is relative: the site works wherever it is served (GitHub Pages serves it under
 * /metadata-driven-templates/). Nothing depends on the time of the build: the same sources
 * give the same pages.
 */
final readonly class SiteBuilder
{
    public const string SEARCH = 'search.json';

    private Environment $twig;

    public function __construct(private SeedRecords $records)
    {
        $this->twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 2).'/resources/site'), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => false,
        ]);
    }

    /**
     * @param array<string, Package> $packages  every version, by directory
     * @param list<IndexEntry>       $entries   their entries in the index, in the same order
     * @param string                 $siteUrl   where the site is served, ending with "/"
     * @param string|null            $publicKey the catalog's public key; null for an unsigned build
     * @param list<string>           $removed   the templates removed from the catalog (yanked.yaml:
     *                                          all): their pages stay, the lists leave them out
     * @param Vendors|null           $vendors   which vendors the maintainers verified
     *
     * @throws BuildFailed
     */
    public function build(array $packages, array $entries, string $output, string $siteUrl, ?string $publicKey, array $removed = [], ?Vendors $vendors = null): void
    {
        $byName = [];
        foreach (array_values($packages) as $i => $package) {
            $byName[$package->name()][] = [$package, $entries[$i]];
        }
        ksort($byName);
        $pages = array_map(fn (array $versions): TemplatePage => TemplatePage::of($versions, $this->records, \in_array($versions[0][0]->name(), $removed, true), $vendors?->isVerified(explode('/', $versions[0][0]->name())[0]) ?? false), array_values($byName));
        $listed = array_values(array_filter($pages, static fn (TemplatePage $page): bool => !$page->removed));

        foreach ($pages as $page) {
            $this->write($output.'/'.$page->path().'index.html', $this->render('template.html.twig', [
                'root' => '../../../',
                'page' => $page,
                'title' => $page->label,
            ]));
        }
        $this->write($output.'/index.html', $this->render('home.html.twig', [
            'root' => '',
            'pages' => $listed,
            'title' => null,
            'index_url' => $siteUrl.'index.json',
            'public_key' => $publicKey,
        ]));
        $this->write($output.'/'.self::SEARCH, json_encode([
            'templates' => array_map(static fn (TemplatePage $page): array => [
                'name' => $page->name,
                'label' => $page->label,
                'description' => $page->description,
                'version' => $page->version,
                'license' => $page->license,
                'verified' => $page->verified,
                'objects' => array_map(static fn (array $object): string => $object['name'], $page->objects),
                'url' => $page->path(),
            ], $listed),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @param array<string, mixed> $context
     *
     * @throws BuildFailed
     */
    private function render(string $template, array $context): string
    {
        try {
            return $this->twig->render($template, $context);
        } catch (TwigError $e) {
            throw new BuildFailed([\sprintf('%s: %s', $template, $e->getMessage())], $e);
        }
    }

    /**
     * @throws BuildFailed
     */
    private function write(string $file, string $content): void
    {
        if (!is_dir(\dirname($file)) && !mkdir(\dirname($file), 0o755, true)) {
            throw new BuildFailed([\sprintf('%s: The directory cannot be created.', \dirname($file))]);
        }
        if (false === file_put_contents($file, $content)) {
            throw new BuildFailed([\sprintf('%s: The file cannot be written.', $file)]);
        }
    }
}
