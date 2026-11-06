<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

use Fedale\TemplateFormat\Format;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The yanked versions (yanked.yaml): hidden from new installs and upgrades, still there for
 * whoever names them. The index flags them, and the flag is signed.
 */
final readonly class Yanked
{
    /**
     * @param array<string, list<string>> $versions by template name
     */
    private function __construct(private array $versions)
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
        foreach ($data as $name => $list) {
            $name = (string) $name;
            if (1 !== preg_match(Format::NAME_PATTERN, $name) || !\is_array($list)) {
                throw new \InvalidArgumentException(\sprintf('yanked.yaml: "%s" is not a template name followed by a list of versions.', $name));
            }
            foreach ($list as $version) {
                if (!\is_string($version) || 1 !== preg_match(Format::VERSION_PATTERN, $version)) {
                    throw new \InvalidArgumentException(\sprintf('yanked.yaml: %s lists something that is not a version (write it quoted, "1.0.0").', $name));
                }
                $versions[$name][] = $version;
            }
        }

        return new self($versions);
    }

    public function has(string $name, string $version): bool
    {
        return \in_array($version, $this->versions[$name] ?? [], true);
    }

    /**
     * @return list<array{string, string}> every yanked version, as [name, version]
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
     * lists again.
     *
     * @return list<array{string, string}>
     */
    public function differences(self $other): array
    {
        $mine = self::keyed($this->all());
        $theirs = self::keyed($other->all());

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
