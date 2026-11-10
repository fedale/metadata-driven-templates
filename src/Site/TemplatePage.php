<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Site;

use Fedale\TemplateFormat\Document;
use Fedale\TemplateFormat\DocumentKind;
use Fedale\TemplateFormat\IndexEntry;
use Fedale\TemplateFormat\Package;
use Fedale\TemplateFormat\SeedRecords;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * What the page of a template shows, read from the documents of its current version: the
 * newest one not yanked (the newest of all when every one is), as an install without a version
 * would take it.
 *
 * Everything here comes from the authors of the templates: the pages escape it (Twig's
 * autoescape), and the README is rendered with raw HTML escaped and unsafe links dropped.
 */
final readonly class TemplatePage
{
    /** The README of a version: optional, Markdown, at the root of its directory. */
    public const string README = 'README.md';
    public const int MAX_README_BYTES = 100_000;

    /**
     * @param list<array{version: string, yanked: bool, url: string, sha256: string, requires: array<string, string>}>                                                                $versions   newest first
     * @param list<array{name: string, label: string, plural: string, fields: list<array{name: string, label: string, type: string, required: bool, unique: bool, target: ?string}>}> $objects
     * @param list<array{name: string, label: string}>                                                                                                                                $forms
     * @param list<array{object: string, records: int, managed: bool}>                                                                                                                $seeds
     * @param list<string>                                                                                                                                                            $locales    the languages its translations add
     * @param array<string, string>                                                                                                                                                   $requires   the templates its current version requires: name => constraint
     * @param list<string>                                                                                                                                                            $requiredBy the templates listed whose current version requires it
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $license,
        public string $version,
        public bool $yanked,
        public array $versions,
        public array $objects,
        public array $forms,
        public array $seeds,
        public array $locales,
        public ?string $readme,
        public bool $removed = false,
        public bool $verified = false,
        public array $requires = [],
        public array $requiredBy = [],
    ) {
    }

    /**
     * The version a page shows, and an install without a version takes: the newest not yanked,
     * the newest of all when every one is.
     *
     * @param non-empty-list<array{Package, IndexEntry}> $versions every version of one template, any order
     *
     * @return array{Package, IndexEntry}
     */
    public static function current(array $versions): array
    {
        usort($versions, static fn (array $a, array $b): int => version_compare($b[0]->version(), $a[0]->version()));
        foreach ($versions as $version) {
            if (!$version[1]->yanked) {
                return $version;
            }
        }

        return $versions[0];
    }

    /**
     * The templates a version requires: name => constraint, the format left out.
     *
     * @return array<string, string>
     */
    public static function requirementsOf(IndexEntry $entry): array
    {
        return array_diff_key($entry->requires, ['format' => true]);
    }

    /**
     * @param non-empty-list<array{Package, IndexEntry}> $versions   every version of one template, any order
     * @param list<string>                               $requiredBy the templates listed whose current version requires it
     */
    public static function of(array $versions, SeedRecords $records, bool $removed = false, bool $verified = false, array $requiredBy = []): self
    {
        usort($versions, static fn (array $a, array $b): int => version_compare($b[0]->version(), $a[0]->version()));
        [$package, $entry] = self::current($versions);
        $spec = $package->manifest->spec;

        $objects = [];
        $labels = [];
        foreach ($package->documentsOf(DocumentKind::Object) as $document) {
            $labels[$document->id] = self::text($document->spec['plural'] ?? null, self::text($document->spec['name'] ?? null));
        }
        foreach ($package->documentsOf(DocumentKind::Object) as $document) {
            $objects[] = self::object($document, $labels);
        }

        $forms = [];
        foreach ($package->documentsOf(DocumentKind::Form) as $document) {
            $definition = \is_array($document->spec['definition'] ?? null) ? $document->spec['definition'] : [];
            $forms[] = ['name' => self::text($document->spec['name'] ?? null), 'label' => self::text($definition['label'] ?? null)];
        }

        $seeds = [];
        foreach ($package->documentsOf(DocumentKind::Seed) as $document) {
            $object = self::text($document->spec['object'] ?? null);
            $seeds[] = [
                'object' => $labels[$object] ?? $object,
                'records' => \count($records->of($package, $document)),
                'managed' => 'managed' === ($document->spec['mode'] ?? null),
            ];
        }

        $locales = [];
        foreach ($package->documentsOf(DocumentKind::Translations) as $document) {
            $locales[] = self::text($document->spec['locale'] ?? null);
        }
        sort($locales);

        return new self(
            $package->name(),
            self::text($spec['label'] ?? null, $package->name()),
            self::text($spec['description'] ?? null),
            self::text($spec['license'] ?? null),
            $package->version(),
            $entry->yanked,
            array_map(static fn (array $version): array => [
                'version' => $version[1]->version,
                'yanked' => $version[1]->yanked,
                'url' => $version[1]->url,
                'sha256' => $version[1]->sha256,
                'requires' => $version[1]->requires,
            ], $versions),
            $objects,
            $forms,
            $seeds,
            array_values(array_unique($locales)),
            self::readme($package),
            $removed,
            $verified,
            self::requirementsOf($entry),
            $requiredBy,
        );
    }

    /**
     * The page's path in the site, from its root: templates/<vendor>/<name>/.
     */
    public function path(): string
    {
        return 'templates/'.$this->name.'/';
    }

    /**
     * @param array<string, string> $labels the plural label of each object, by id
     *
     * @return array{name: string, label: string, plural: string, fields: list<array{name: string, label: string, type: string, required: bool, unique: bool, target: ?string}>}
     */
    private static function object(Document $document, array $labels): array
    {
        $fields = [];
        foreach (\is_array($document->spec['fields'] ?? null) ? $document->spec['fields'] : [] as $field) {
            if (!\is_array($field)) {
                continue;
            }
            $settings = \is_array($field['settings'] ?? null) ? $field['settings'] : [];
            $target = \is_string($settings['target'] ?? null) ? $settings['target'] : null;
            $fields[] = [
                'name' => self::text($field['name'] ?? null),
                'label' => self::text($field['label'] ?? null),
                'type' => self::text($field['type'] ?? null),
                'required' => true === ($field['required'] ?? false),
                'unique' => true === ($field['unique'] ?? false),
                'target' => null === $target ? null : ($labels[$target] ?? $target),
            ];
        }

        return [
            'name' => self::text($document->spec['name'] ?? null),
            'label' => self::text($document->spec['label'] ?? null),
            'plural' => self::text($document->spec['plural'] ?? null),
            'fields' => $fields,
        ];
    }

    /**
     * The README rendered as HTML; null when the version has none.
     */
    private static function readme(Package $package): ?string
    {
        $file = $package->file(self::README);
        if (null === $file) {
            return null;
        }
        $markdown = file_get_contents($file, false, null, 0, self::MAX_README_BYTES);
        if (false === $markdown || '' === trim($markdown)) {
            return null;
        }
        // Cut at the limit, maybe inside a character: drop what is not UTF-8.
        $markdown = mb_scrub($markdown, 'UTF-8');
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        return $converter->convert($markdown)->getContent();
    }

    private static function text(mixed $value, string $default = ''): string
    {
        return \is_string($value) && '' !== $value ? $value : $default;
    }
}
