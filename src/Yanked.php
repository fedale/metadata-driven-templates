<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

use Fedale\TemplateFormat\Format;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The yanked versions (yanked.yaml): hidden from new installs and upgrades, still there for
 * whoever names them. The index flags them, and the flag is signed.
 *
 * `vendor/name: all` removes a template: every version is yanked, and the catalog stops
 * listing it. Its versions stay installable by their exact version: installations rely on them
 * to upgrade.
 */
final readonly class Yanked
{
    /** In place of a list of versions: every version of the template. */
    public const string ALL = 'all';

    /**
     * @param array<string, list<string>> $versions by template name
     * @param list<string>                $removed  the templates whose every version is yanked
     */
    private function __construct(private array $versions, private array $removed = [])
    {
    }

    /**
     * @throws \InvalidArgumentException the file is not a valid list of versions
     */
    public static function fromYaml(string $yaml): self
    {
        try {
            $data = Yaml::parse($yaml) ?? [];
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('yanked.yaml is not valid YAML: %s', $e->getMessage()), 0, $e);
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('yanked.yaml maps each template to its yanked versions.');
        }

        $versions = [];
        $removed = [];
        foreach ($data as $name => $list) {
            $name = (string) $name;
            if (1 === preg_match(Format::NAME_PATTERN, $name) && self::ALL === $list) {
                $removed[] = $name;
                continue;
            }
            if (1 !== preg_match(Format::NAME_PATTERN, $name) || !\is_array($list)) {
                throw new \InvalidArgumentException(\sprintf('yanked.yaml: "%s" is not a template name followed by a list of versions, or by "all".', $name));
            }
            foreach ($list as $version) {
                if (!\is_string($version) || 1 !== preg_match(Format::VERSION_PATTERN, $version)) {
                    throw new \InvalidArgumentException(\sprintf('yanked.yaml: %s lists something that is not a version (write it quoted, "1.0.0").', $name));
                }
                $versions[$name][] = $version;
            }
        }

        return new self($versions, $removed);
    }

    public function has(string $name, string $version): bool
    {
        return $this->isRemoved($name) || \in_array($version, $this->versions[$name] ?? [], true);
    }

    /**
     * Whether every version of the template is yanked: the catalog no longer lists it.
     */
    public function isRemoved(string $name): bool
    {
        return \in_array($name, $this->removed, true);
    }

    /**
     * @return list<string> the removed templates
     */
    public function removed(): array
    {
        return $this->removed;
    }

    /**
     * @return list<array{string, string}> every version yanked by name, as [name, version]; a
     *                                     removed template is in removed()
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->versions as $name => $versions) {
            foreach ($versions as $version) {
                $all[] = [$name, $version];
            }
        }

        return $all;
    }

    /**
     * The versions yanked in one list and not in the other: what a change of the file yanks or
     * lists again. A template removed or listed again is [name, "all"].
     *
     * @return list<array{string, string}>
     */
    public function differences(self $other): array
    {
        $mine = self::keyed([...$this->all(), ...array_map(static fn (string $name): array => [$name, self::ALL], $this->removed)]);
        $theirs = self::keyed([...$other->all(), ...array_map(static fn (string $name): array => [$name, self::ALL], $other->removed)]);

        return array_values(array_diff_key($mine, $theirs) + array_diff_key($theirs, $mine));
    }

    /**
     * @param list<array{string, string}> $pairs
     *
     * @return array<string, array{string, string}>
     */
    private static function keyed(array $pairs): array
    {
        $keyed = [];
        foreach ($pairs as $pair) {
            $keyed[$pair[0].'@'.$pair[1]] = $pair;
        }

        return $keyed;
    }
}
