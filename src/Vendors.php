<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The vendor namespaces of the catalog (vendors.yaml): who may publish under "acme/", and
 * whether the maintainers vouch for the vendor (`verified: true`).
 *
 * Owners are GitHub logins, of users or of organizations: the author of a pull request owns a
 * vendor when listed, or when a public member of a listed organization (OrganizationMembers,
 * asked by PullRequestCheck). Teams are not supported.
 */
final readonly class Vendors
{
    /** The vendor part of a template name ("acme" in "acme/shop"). */
    public const string VENDOR_PATTERN = '~^[a-z][a-z0-9-]*$~';

    /** A GitHub login: letters, digits and single hyphens, at most 39 characters. */
    private const string LOGIN_PATTERN = '~^(?=.{1,39}$)[A-Za-z0-9]+(-[A-Za-z0-9]+)*$~';

    /**
     * @param array<string, list<string>> $owners   the owners' logins, lowercase, by vendor
     * @param list<string>                $verified the vendors the maintainers vouch for
     */
    private function __construct(private array $owners, private array $verified = [])
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
        $verified = [];
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
            $flag = $entry['verified'] ?? false;
            if (!\is_bool($flag)) {
                throw new \InvalidArgumentException(\sprintf('vendors.yaml: %s: verified is true or false.', $vendor));
            }
            if ($flag) {
                $verified[] = $vendor;
            }
            foreach (array_keys($entry) as $key) {
                if (!\in_array($key, ['owners', 'verified'], true)) {
                    throw new \InvalidArgumentException(\sprintf('vendors.yaml: %s: "%s" is not a setting of a vendor (owners, verified).', $vendor, (string) $key));
                }
            }
        }

        return new self($owners, $verified);
    }

    public function has(string $vendor): bool
    {
        return isset($this->owners[$vendor]);
    }

    /**
     * Whether the login is listed among the owners. GitHub logins are case-insensitive:
     * "Mario-Rossi" owns what "mario-rossi" owns. A member of a listed organization is not
     * listed: PullRequestCheck asks GitHub about them.
     */
    public function isOwner(string $vendor, string $login): bool
    {
        return \in_array(strtolower($login), $this->owners[$vendor] ?? [], true);
    }

    /**
     * @return list<string> the owners' logins (users or organizations), lowercase
     */
    public function owners(string $vendor): array
    {
        return $this->owners[$vendor] ?? [];
    }

    public function isVerified(string $vendor): bool
    {
        return \in_array($vendor, $this->verified, true);
    }

    /**
     * The vendors verified in one file and not in the other: what a change of vendors.yaml
     * verifies or stops verifying.
     *
     * @return list<string>
     */
    public function verificationChanges(self $other): array
    {
        $changes = [...array_diff($this->verified, $other->verified), ...array_diff($other->verified, $this->verified)];
        sort($changes);

        return array_values(array_unique($changes));
    }
}
