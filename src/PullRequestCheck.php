<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

use Fedale\TemplateCatalog\Owners\Maintainers;
use Fedale\TemplateCatalog\Owners\MembershipUnknown;
use Fedale\TemplateCatalog\Owners\OrganizationMembers;
use Fedale\TemplateFormat\Format;
use Fedale\TemplateFormat\InvalidPackage;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\PackageChecker;
use Fedale\TemplateFormat\PackageDirectory;
use Fedale\TemplateFormat\Problem;
use Fedale\TemplateFormat\Versions\ItemStates;
use Fedale\TemplateFormat\Versions\VersionCheck;

/**
 * What a pull request must satisfy before a maintainer reviews it.
 *
 * Under templates/, a pull request may only add version directories,
 * templates/<vendor>/<name>/<version>/: a published version never changes, since installations
 * compare against it when they upgrade. Each added version must be a valid package (the format
 * library's checks), match its path, be higher than the versions already listed, stay under the
 * size limit, and belong to a vendor the author owns. Its number must fit what it changed
 * against the highest version already listed (VersionCheck, the rules metadata-driven applies
 * when it upgrades): installations rely on a minor version removing nothing.
 *
 * yanked.yaml may yank a version, or list it again, of a vendor the author owns; or remove a
 * template (`vendor/name: all`), which then takes no new version until it is listed again.
 *
 * vendors.yaml must stay valid, and only a maintainer (CODEOWNERS) verifies a vendor or stops
 * verifying it. Anything else about it, and catalog.pub or the tools, is left to the
 * maintainers' review.
 *
 * The author owns a vendor when listed among its owners, or when a public member of an
 * organization listed there.
 */
final readonly class PullRequestCheck
{
    public const string TEMPLATES = 'templates';
    public const string YANKED = 'yanked.yaml';
    public const string VENDORS = 'vendors.yaml';

    /**
     * 20 MB per version, files of the seeds included: well below GitHub's 50 MB warning per file
     * and metadata-driven's default 50 MB download limit.
     */
    public const int MAX_VERSION_BYTES = 20_000_000;

    public function __construct(
        private PackageDirectory $reader,
        private PackageChecker $checker,
        private ItemStates $states,
        private OrganizationMembers $members,
    ) {
    }

    /**
     * @param list<ChangedFile> $changes what the pull request changes
     * @param BaseBranch        $base    the vendors, published and yanked versions of the base: a
     *                                   pull request cannot register a vendor and publish under it
     *                                   at once
     * @param string|null       $author  the GitHub login of the pull request's author; null skips
     *                                   the ownership checks (a maintainer running it locally)
     *
     * @return list<string> every problem found, empty when the pull request may be reviewed
     */
    public function check(string $root, array $changes, BaseBranch $base, ?string $author): array
    {
        $problems = [];
        $versions = [];
        foreach ($changes as $change) {
            $parts = explode('/', $change->path);
            if (self::YANKED === $change->path) {
                $problems = [...$problems, ...$this->checkYanked($root, $base, $author)];
                continue;
            }
            if (self::VENDORS === $change->path) {
                $problems = [...$problems, ...self::checkVendors($root, $base, $author)];
                continue;
            }
            if (self::TEMPLATES !== $parts[0]) {
                continue;
            }
            if (\count($parts) < 5) {
                $problems[] = \sprintf('%s: A template\'s files go in templates/<vendor>/<name>/<version>/.', $change->path);
                continue;
            }
            if (!$change->isAdded() || \in_array(implode('/', \array_slice($parts, 0, 4)), $base->published, true)) {
                $problems[] = \sprintf('%s: A published version never changes: publish a new version instead.', $change->path);
                continue;
            }
            $versions[implode('/', \array_slice($parts, 0, 4))] = [$parts[1], $parts[2], $parts[3]];
        }

        ksort($versions);
        foreach ($versions as $directory => [$vendor, $name, $version]) {
            $listed = [];
            foreach ($base->published as $publishedDirectory) {
                [, $publishedVendor, $publishedName, $publishedVersion] = explode('/', $publishedDirectory) + ['', '', '', ''];
                if ($publishedVendor === $vendor && $publishedName === $name) {
                    $listed[] = $publishedVersion;
                }
            }
            $problems = [...$problems, ...$this->checkVersion($root, $directory, $vendor, $name, $version, $listed, $base->vendors, $author, $base->yanked->isRemoved($vendor.'/'.$name))];
        }

        return $problems;
    }

    /**
     * @param list<string> $listed the versions of this template already published
     *
     * @return list<string>
     */
    private function checkVersion(string $root, string $directory, string $vendor, string $name, string $version, array $listed, Vendors $vendors, ?string $author, bool $removed = false): array
    {
        $template = $vendor.'/'.$name;
        if (1 !== preg_match(Format::NAME_PATTERN, $template)) {
            return [\sprintf('%s: "%s" is not a template name (vendor/name, lowercase letters, digits and hyphens).', $directory, $template)];
        }
        if (1 !== preg_match(Format::VERSION_PATTERN, $version)) {
            return [\sprintf('%s: "%s" is not a version (major.minor.patch, e.g. 1.0.0).', $directory, $version)];
        }

        $problems = [];
        if ($removed) {
            $problems[] = \sprintf('%s: %s is removed (yanked.yaml: all): list it again before publishing a new version.', $directory, $template);
        }
        if (!$vendors->has($vendor)) {
            $problems[] = \sprintf('%s: The vendor "%s" is not registered: add it to vendors.yaml in a pull request of its own first.', $directory, $vendor);
        } elseif (null !== $author && true !== ($owns = $this->owns($vendors, $vendor, $author))) {
            $problems[] = \is_string($owns) ? \sprintf('%s: %s', $directory, $owns) : \sprintf('%s: %s is not an owner of the vendor "%s" (vendors.yaml).', $directory, $author, $vendor);
        }

        // Nothing is read through a link: the reader would follow it out of the checkout, and a
        // problem quoting what it read would publish a file of the runner in the check's log.
        $links = Links::in($root, $directory);
        if ([] !== $links) {
            return [...$problems, ...$links];
        }
        $problems = [...$problems, ...self::checkSize($root.'/'.$directory, $directory)];

        $newer = array_filter(
            $listed,
            static fn (string $listed): bool => version_compare($listed, $version, '>='),
        );
        if ([] !== $newer) {
            usort($newer, static fn (string $a, string $b): int => version_compare($a, $b));
            $problems[] = \sprintf('%s: %s %s is already listed: the new version must be higher.', $directory, $template, end($newer));
        }

        try {
            $package = $this->reader->read($root.'/'.$directory);
        } catch (InvalidPackage $e) {
            return [...$problems, ...self::located($directory, $e->problems)];
        }
        if ($package->name() !== $template || $package->version() !== $version) {
            $problems[] = \sprintf('%s: The manifest says %s %s: the directory must be templates/%s/%s/.', $directory, $package->name(), $package->version(), $package->name(), $package->version());
        }

        $structure = self::located($directory, $this->checker->check($package));
        if ([] !== $structure || [] !== $newer) {
            return [...$problems, ...$structure];
        }

        return [...$problems, ...$this->checkNumber($root, $package, $directory, $listed)];
    }

    /**
     * Whether the version's number fits what it changed against the highest version listed. The
     * listed versions are read from the checkout: a pull request cannot change them (checked
     * above), so they are the published ones.
     *
     * @param list<string> $listed
     *
     * @return list<string>
     */
    private function checkNumber(string $root, Package $package, string $directory, array $listed): array
    {
        if ([] === $listed) {
            return [];
        }
        usort($listed, static fn (string $a, string $b): int => version_compare($a, $b));
        $previous = end($listed);
        $previousDirectory = self::TEMPLATES.'/'.$package->name().'/'.$previous;
        $links = Links::in($root, $previousDirectory);
        if ([] !== $links) {
            return $links;
        }
        try {
            $before = $this->reader->read($root.'/'.$previousDirectory);
        } catch (InvalidPackage $e) {
            return [\sprintf('%s: The previous version, %s, cannot be read to compare with it.', $directory, $previous)];
        }

        $problems = VersionCheck::problems(
            $this->states->of($before),
            $this->states->of($package),
            $previous,
            $package->version(),
            self::migrates($package, $previous),
        );

        return array_map(static fn (string $problem): string => $directory.'/'.$problem, $problems);
    }

    /**
     * Whether the manifest carries migration steps for a version after $previous, up to its own.
     */
    private static function migrates(Package $package, string $previous): bool
    {
        $migrations = $package->manifest->spec['migrations'] ?? null;
        foreach (\is_array($migrations) ? $migrations : [] as $version => $steps) {
            if (version_compare((string) $version, $previous, '>') && version_compare((string) $version, $package->version(), '<=') && [] !== $steps) {
                return true;
            }
        }

        return false;
    }

    /**
     * A change of yanked.yaml: valid, naming published versions, each yanked or listed again by
     * an owner of its vendor.
     *
     * @return list<string>
     */
    private function checkYanked(string $root, BaseBranch $base, ?string $author): array
    {
        $file = $root.'/'.self::YANKED;
        $content = is_file($file) && !is_link($file) ? file_get_contents($file) : '';
        try {
            $yanked = Yanked::fromYaml(false === $content ? '' : $content);
        } catch (\InvalidArgumentException $e) {
            return [$e->getMessage()];
        }

        $problems = [];
        foreach ($yanked->differences($base->yanked) as [$name, $version]) {
            $vendor = explode('/', $name)[0];
            if (Yanked::ALL === $version) {
                if ([] === array_filter($base->published, static fn (string $directory): bool => str_starts_with($directory, self::TEMPLATES.'/'.$name.'/'))) {
                    $problems[] = \sprintf('%s: %s is not published.', self::YANKED, $name);
                } elseif (null !== $author && true !== ($owns = $this->owns($base->vendors, $vendor, $author))) {
                    $problems[] = \is_string($owns) ? \sprintf('%s: %s', self::YANKED, $owns) : \sprintf('%s: %s is not an owner of the vendor "%s": only its owners remove its templates.', self::YANKED, $author, $vendor);
                }
                continue;
            }
            if (!\in_array(self::TEMPLATES.'/'.$name.'/'.$version, $base->published, true)) {
                $problems[] = \sprintf('%s: %s %s is not published.', self::YANKED, $name, $version);
            } elseif (null !== $author && true !== ($owns = $this->owns($base->vendors, $vendor, $author))) {
                $problems[] = \is_string($owns) ? \sprintf('%s: %s', self::YANKED, $owns) : \sprintf('%s: %s is not an owner of the vendor "%s": only its owners yank its versions.', self::YANKED, $author, $vendor);
            }
        }

        return $problems;
    }

    /**
     * Whether the author owns the vendor: listed among its owners, or a public member of an
     * organization listed there.
     *
     * @return bool|string true, false, or why GitHub could not tell
     */
    private function owns(Vendors $vendors, string $vendor, string $author): bool|string
    {
        if ($vendors->isOwner($vendor, $author)) {
            return true;
        }
        foreach ($vendors->owners($vendor) as $owner) {
            try {
                if ($this->members->isPublicMember($owner, $author)) {
                    return true;
                }
            } catch (MembershipUnknown $e) {
                return $e->getMessage().' Run the check again.';
            }
        }

        return false;
    }

    /**
     * A change of vendors.yaml: still valid, and verified or unverified by a maintainer only.
     *
     * @return list<string>
     */
    private static function checkVendors(string $root, BaseBranch $base, ?string $author): array
    {
        $file = $root.'/'.self::VENDORS;
        $content = is_file($file) && !is_link($file) ? file_get_contents($file) : '';
        try {
            $vendors = Vendors::fromYaml(false === $content ? '' : $content);
        } catch (\InvalidArgumentException $e) {
            return [$e->getMessage()];
        }

        $problems = [];
        foreach ($vendors->verificationChanges($base->vendors) as $vendor) {
            if (null !== $author && !($base->maintainers?->has($author) ?? false)) {
                $problems[] = \sprintf('%s: %s: only the maintainers (%s) verify a vendor, or stop verifying it.', self::VENDORS, $vendor, Maintainers::CODEOWNERS);
            }
        }

        return $problems;
    }

    /**
     * The size of a version: the sum of every file, with no link left to follow.
     *
     * @return list<string>
     */
    private static function checkSize(string $path, string $directory): array
    {
        $bytes = 0;
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($entries as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $bytes += $entry->getSize();
            }
        }
        if ($bytes > self::MAX_VERSION_BYTES) {
            return [\sprintf('%s: %.1f MB: a version may weigh %d MB at most, files included.', $directory, $bytes / 1_000_000, self::MAX_VERSION_BYTES / 1_000_000)];
        }

        return [];
    }

    /**
     * @param list<Problem> $problems located in the package
     *
     * @return list<string> located in the repository
     */
    public static function located(string $directory, array $problems): array
    {
        return array_map(static fn (Problem $problem): string => $directory.('' === $problem->path ? ': ' : '/').$problem, $problems);
    }
}
