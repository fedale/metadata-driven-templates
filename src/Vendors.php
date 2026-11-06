<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The vendor namespaces of the catalog (vendors.yaml): who may publish under "acme/".
 *
 * Owners are GitHub user logins, not organizations or teams: the check compares them with the
 * author of the pull request, and membership of an organization cannot be read without the
 * GitHub API.
 */
final readonly class Vendors
{
    /** The vendor part of a template name ("acme" in "acme/shop"). */
    public const string VENDOR_PATTERN = '~^[a-z][a-z0-9-]*$~';

    /** A GitHub login: letters, digits and single hyphens, at most 39 characters. */
    private const string LOGIN_PATTERN = '~^(?=.{1,39}$)[A-Za-z0-9]+(-[A-Za-z0-9]+)*$~';

    /**
     * @param array<string, list<string>> $owners the owners' logins, lowercase, by vendor
     */
    private function __construct(private array $owners)
    {
    }

    /**
     * @throws \InvalidArgumentException the file is not a valid list of vendors
     */
    public static function fromYaml(string $yaml): self
    {
        try {
            $data = Yaml::parse($yaml) ?? [];
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(\sprintf('vendors.yaml is not valid YAML: %s', $e->getMessage()), 0, $e);
        }
        if (!\is_array($data)) {
            throw new \InvalidArgumentException('vendors.yaml maps each vendor to its owners.');
        }

        $owners = [];
        foreach ($data as $vendor => $entry) {
            $vendor = (string) $vendor;
            if (1 !== preg_match(self::VENDOR_PATTERN, $vendor)) {
                throw new \InvalidArgumentException(\sprintf('vendors.yaml: "%s" is not a vendor name (lowercase letters, digits and hyphens, starting with a letter).', $vendor));
            }
            $logins = \is_array($entry) ? ($entry['owners'] ?? null) : null;
            if (!\is_array($logins) || [] === $logins) {
                throw new \InvalidArgumentException(\sprintf('vendors.yaml: %s needs a list of owners.', $vendor));
            }
            foreach ($logins as $login) {
                if (!\is_string($login) || 1 !== preg_match(self::LOGIN_PATTERN, $login)) {
                    throw new \InvalidArgumentException(\sprintf('vendors.yaml: %s has an owner that is not a GitHub login.', $vendor));
                }
                $owners[$vendor][] = strtolower($login);
            }
        }

        return new self($owners);
    }

    public function has(string $vendor): bool
    {
        return isset($this->owners[$vendor]);
    }

    /**
     * GitHub logins are case-insensitive: "Mario-Rossi" owns what "mario-rossi" owns.
     */
    public function isOwner(string $vendor, string $login): bool
    {
        return \in_array(strtolower($login), $this->owners[$vendor] ?? [], true);
    }
}
