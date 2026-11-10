<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

use Fedale\TemplateCatalog\Site\SiteBuilder;
use Fedale\TemplateFormat\Archive;
use Fedale\TemplateFormat\Format;
use Fedale\TemplateFormat\IndexEntry;
use Fedale\TemplateFormat\InvalidPackage;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\Signature;
use Fedale\TemplateFormat\TemplateIndex;

/**
 * Builds what GitHub Pages serves: index.json, an archive per version and the pages of the
 * catalog (SiteBuilder), from the sources of every version.
 *
 * Stateless on purpose: archives are reproducible and Ed25519 signatures deterministic, so the
 * same sources always give the same site, and the whole site is rebuilt at every publication.
 * Nothing is written unless every version is valid: a broken main branch publishes nothing,
 * rather than an index missing a version installations rely on.
 */
final readonly class CatalogBuilder
{
    public const string PUBLIC_KEY = 'catalog.pub';
    public const string ARCHIVES = 'archives';
    public const string SITE_URL = 'https://fedale.github.io/metadata-driven-templates/';

    /** GitHub Pages refuses sites above 1 GB: stop well before. */
    public const int MAX_SITE_BYTES = 900_000_000;

    public function __construct(
        private PackageDirectory $reader,
        private PackageChecker $checker,
        private Archive $archive,
        private SiteBuilder $site,
    ) {
    }

    /**
     * @param string|null $secretKey the catalog's Ed25519 secret key; null builds an unsigned
     *                               index, to look at locally, never to publish
     * @param string      $siteUrl   where the site is served, ending with "/"
     *
     * @return list<IndexEntry> what the index lists
     *
     * @throws BuildFailed
     */
    public function build(string $root, string $output, ?string $secretKey, string $siteUrl = self::SITE_URL): array
    {
        $packages = $this->versions($root);
        $yanked = $this->yanked($root, $packages);
        $this->checkRequirements($packages, $yanked);
        $publicKey = null === $secretKey ? null : $this->publicKey($root);
        if (file_exists($output) && (!is_dir($output) || [] !== array_diff(scandir($output) ?: [], ['.', '..']))) {
            throw new BuildFailed([\sprintf('%s: The site is built into a new or empty directory.', $output)]);
        }

        $index = TemplateIndex::empty();
        $entries = [];
        $bytes = 0;
        foreach ($packages as $directory => $package) {
            [$vendor, $name] = explode('/', $package->name());
            $path = self::ARCHIVES.'/'.$vendor.'/'.$name.'-'.$package->version().'.tar.gz';
            if (!is_dir(\dirname($output.'/'.$path)) && !mkdir(\dirname($output.'/'.$path), 0o755, true)) {
                throw new BuildFailed([\sprintf('%s: The directory cannot be created.', \dirname($output.'/'.$path))]);
            }
            try {
                $this->archive->pack($root.'/'.$directory, $output.'/'.$path);
            } catch (\RuntimeException $e) {
                throw new BuildFailed([\sprintf('%s: %s', $directory, $e->getMessage())], $e);
            }
            $bytes += (int) filesize($output.'/'.$path);

            $entry = new IndexEntry($package->name(), $package->version(), $path, (string) hash_file('sha256', $output.'/'.$path), null, self::requires($package), $yanked->has($package->name(), $package->version()));
            if (null !== $secretKey && null !== $publicKey) {
                $entry = $this->signed($entry, $secretKey, $publicKey);
            }
            $index = $index->with($entry);
            $entries[] = $entry;
        }
        if ($bytes > self::MAX_SITE_BYTES) {
            throw new BuildFailed([\sprintf('The archives weigh %.1f MB: GitHub Pages serves 1000 MB at most. Move them to Releases (docs/internal/plan.md).', $bytes / 1_000_000)]);
        }

        if (!is_dir($output) && !mkdir($output, 0o755, true)) {
            throw new BuildFailed([\sprintf('%s: The directory cannot be created.', $output)]);
        }
        $files = ['index.json' => $index->toJson()];
        if (null !== $publicKey) {
            $files[self::PUBLIC_KEY] = $publicKey."\n";
        }
        foreach ($files as $file => $content) {
            if (false === file_put_contents($output.'/'.$file, $content)) {
                throw new BuildFailed([\sprintf('%s/%s: The file cannot be written.', $output, $file)]);
            }
        }
        $this->site->build($packages, $entries, $output, $siteUrl, $publicKey, $yanked->removed(), $this->vendors($root));

        return $entries;
    }

    /**
     * Every version directory, read and matched against its path; the format's checks follow,
     * once every version is known (checkRequirements()).
     *
     * @return array<string, Package> by directory, sorted
     *
     * @throws BuildFailed a version is invalid
     */
    private function versions(string $root): array
    {
        $problems = [];
        $packages = [];
        $directories = glob($root.'/'.PullRequestCheck::TEMPLATES.'/*/*/*', \GLOB_ONLYDIR) ?: [];
        sort($directories);
        foreach ($directories as $path) {
            $directory = substr($path, \strlen($root) + 1);
            [, $vendor, $name, $version] = explode('/', $directory);
            $links = Links::in($root, $directory);
            if ([] !== $links) {
                $problems = [...$problems, ...$links];
                continue;
            }
            if (1 !== preg_match(Format::NAME_PATTERN, $vendor.'/'.$name) || 1 !== preg_match(Format::VERSION_PATTERN, $version)) {
                $problems[] = \sprintf('%s: Not a version directory, templates/<vendor>/<name>/<version>.', $directory);
                continue;
            }
            try {
                $package = $this->reader->read($path);
            } catch (InvalidPackage $e) {
                $problems = [...$problems, ...PullRequestCheck::located($directory, $e->problems)];
                continue;
            }
            if ($package->name() !== $vendor.'/'.$name || $package->version() !== $version) {
                $problems[] = \sprintf('%s: The manifest says %s %s.', $directory, $package->name(), $package->version());
                continue;
            }
            $packages[$directory] = $package;
        }
        if ([] !== $problems) {
            throw new BuildFailed($problems);
        }

        return $packages;
    }

    /**
     * Every version checked by the format's checker against the templates it requires, as the
     * catalog has them (Requirements): the highest fitting version not yanked, a yanked one
     * when there is no other (a version listed once stays valid when its requirement is yanked).
     *
     * @param array<string, Package> $packages by directory
     *
     * @throws BuildFailed a version is invalid
     */
    private function checkRequirements(array $packages, Yanked $yanked): void
    {
        $versions = [];
        foreach ($packages as $package) {
            $versions[$package->name()][] = $package->version();
        }
        $requirements = new Requirements(
            $versions,
            $yanked,
            static fn (string $name, string $version): ?Package => $packages[PullRequestCheck::TEMPLATES.'/'.$name.'/'.$version] ?? null,
            yankedIfNothingElse: true,
        );
        $problems = [];
        foreach ($packages as $directory => $package) {
            $problems = [...$problems, ...PullRequestCheck::located($directory, $requirements->check($this->checker, $package))];
        }
        if ([] !== $problems) {
            throw new BuildFailed($problems);
        }
    }

    /**
     * @param array<string, Package> $packages
     *
     * @throws BuildFailed yanked.yaml is invalid, or names a version the catalog does not have
     */
    private function yanked(string $root, array $packages): Yanked
    {
        $file = $root.'/'.PullRequestCheck::YANKED;
        try {
            $yanked = Yanked::fromYaml(is_file($file) && !is_link($file) ? (string) file_get_contents($file) : '');
        } catch (\InvalidArgumentException $e) {
            throw new BuildFailed([$e->getMessage()]);
        }

        $problems = [];
        foreach ($yanked->all() as [$name, $version]) {
            if (!isset($packages[PullRequestCheck::TEMPLATES.'/'.$name.'/'.$version])) {
                $problems[] = \sprintf('%s: %s %s is not published.', PullRequestCheck::YANKED, $name, $version);
            }
        }
        foreach ($yanked->removed() as $name) {
            if ([] === array_filter(array_keys($packages), static fn (string $directory): bool => str_starts_with($directory, PullRequestCheck::TEMPLATES.'/'.$name.'/'))) {
                $problems[] = \sprintf('%s: %s is not published.', PullRequestCheck::YANKED, $name);
            }
        }
        if ([] !== $problems) {
            throw new BuildFailed($problems);
        }

        return $yanked;
    }

    /**
     * @throws BuildFailed vendors.yaml is invalid
     */
    private function vendors(string $root): Vendors
    {
        $file = $root.'/'.PullRequestCheck::VENDORS;
        try {
            return Vendors::fromYaml(is_file($file) && !is_link($file) ? (string) file_get_contents($file) : '');
        } catch (\InvalidArgumentException $e) {
            throw new BuildFailed([$e->getMessage()]);
        }
    }

    /**
     * @throws BuildFailed
     */
    private function publicKey(string $root): string
    {
        $file = $root.'/'.self::PUBLIC_KEY;
        $key = is_file($file) && !is_link($file) ? trim((string) file_get_contents($file)) : '';
        if (!Signature::isPublicKey($key)) {
            throw new BuildFailed([\sprintf('%s: The catalog\'s public key is missing or invalid.', self::PUBLIC_KEY)]);
        }

        return $key;
    }

    /**
     * Signs an entry, and checks the signature against catalog.pub: a secret key that does not
     * match it would publish an index every installation refuses.
     *
     * @throws BuildFailed
     */
    private function signed(IndexEntry $entry, string $secretKey, string $publicKey): IndexEntry
    {
        try {
            $signature = Signature::sign($entry, $secretKey);
        } catch (\InvalidArgumentException $e) {
            throw new BuildFailed([$e->getMessage()]);
        }
        $signed = new IndexEntry($entry->name, $entry->version, $entry->url, $entry->sha256, $signature, $entry->requires, $entry->yanked);
        if (!Signature::verify($signed, $publicKey)) {
            throw new BuildFailed([\sprintf('The secret key does not match %s.', self::PUBLIC_KEY)]);
        }

        return $signed;
    }

    /**
     * @return array<string, string>
     */
    private static function requires(Package $package): array
    {
        $requires = [];
        foreach (\is_array($package->manifest->spec['requires'] ?? null) ? $package->manifest->spec['requires'] : [] as $name => $constraint) {
            $requires[(string) $name] = \is_scalar($constraint) ? (string) $constraint : '';
        }

        return $requires;
    }
}
