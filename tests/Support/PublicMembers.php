<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests\Support;

use Fedale\TemplateCatalog\Owners\MembershipUnknown;
use Fedale\TemplateCatalog\Owners\OrganizationMembers;

/**
 * Organizations and their public members, in place of the GitHub API.
 */
final class PublicMembers implements OrganizationMembers
{
    /** @var list<array{string, string}> what was asked, as [organization, login] */
    public array $asked = [];

    /**
     * @param array<string, list<string>> $members by organization
     */
    public function __construct(private readonly array $members = [], private readonly bool $unreachable = false)
    {
    }

    public function isPublicMember(string $organization, string $login): bool
    {
        $this->asked[] = [$organization, $login];
        if ($this->unreachable) {
            throw new MembershipUnknown(\sprintf('GitHub answered 503 when asked whether %s is a public member of %s.', $login, $organization));
        }

        return \in_array($login, $this->members[$organization] ?? [], true);
    }
}
