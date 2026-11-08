<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Owners;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks the GitHub API: GET /orgs/{org}/public_members/{username} answers 204 for a public
 * member, 404 otherwise (a private member, a user in place of an organization, no account).
 *
 * Public memberships only: the Action's read-only token cannot see private ones, and a member
 * who wants to publish makes their membership public. The token only lifts the API's rate
 * limit; without it, 60 requests an hour per address.
 */
final readonly class GitHubOrganizationMembers implements OrganizationMembers
{
    private const string API = 'https://api.github.com';

    public function __construct(
        private HttpClientInterface $http,
        private ?string $token = null,
    ) {
    }

    public function isPublicMember(string $organization, string $login): bool
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28', 'User-Agent' => 'metadata-driven-templates-check'];
        if (null !== $this->token && '' !== $this->token) {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }
        try {
            $status = $this->http->request('GET', \sprintf('%s/orgs/%s/public_members/%s', self::API, rawurlencode($organization), rawurlencode($login)), [
                'headers' => $headers,
                'max_redirects' => 0,
                'timeout' => 15,
            ])->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new MembershipUnknown(\sprintf('GitHub could not be asked whether %s is a public member of %s: %s', $login, $organization, $e->getMessage()), 0, $e);
        }

        return match ($status) {
            204 => true,
            404 => false,
            default => throw new MembershipUnknown(\sprintf('GitHub answered %d when asked whether %s is a public member of %s.', $status, $login, $organization)),
        };
    }
}
