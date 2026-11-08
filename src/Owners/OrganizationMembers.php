<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Owners;

/**
 * Whether a GitHub user is a public member of an organization: an organization listed among a
 * vendor's owners lends the vendor to its public members.
 */
interface OrganizationMembers
{
    /**
     * False as well when $organization is a user, or no account at all.
     *
     * @throws MembershipUnknown GitHub could not be asked: the check must not guess
     */
    public function isPublicMember(string $organization, string $login): bool;
}
