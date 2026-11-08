<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests\Owners;

use Fedale\TemplateCatalog\Owners\GitHubOrganizationMembers;
use Fedale\TemplateCatalog\Owners\MembershipUnknown;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubOrganizationMembersTest extends TestCase
{
    public function testAsksForAPublicMembership(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $headers = $options['normalized_headers'] ?? null;
            $authorization = \is_array($headers) && \is_array($headers['authorization'] ?? null) ? ($headers['authorization'][0] ?? null) : null;
            $requests[] = [$method, $url, $authorization];

            return new MockResponse('', ['http_code' => str_ends_with($url, '/anna') ? 204 : 404]);
        });
        $members = new GitHubOrganizationMembers($http, 'a-token');

        self::assertTrue($members->isPublicMember('acme-corp', 'anna'));
        self::assertFalse($members->isPublicMember('acme-corp', 'bruno'));
        self::assertSame(['GET', 'https://api.github.com/orgs/acme-corp/public_members/anna', 'Authorization: Bearer a-token'], $requests[0]);
    }

    public function testDoesNotGuessOnAnotherAnswer(): void
    {
        $members = new GitHubOrganizationMembers(new MockHttpClient(new MockResponse('', ['http_code' => 403])));

        $this->expectException(MembershipUnknown::class);
        $this->expectExceptionMessage('GitHub answered 403 when asked whether anna is a public member of acme-corp.');
        $members->isPublicMember('acme-corp', 'anna');
    }

    public function testDoesNotGuessWhenGitHubIsUnreachable(): void
    {
        $members = new GitHubOrganizationMembers(new MockHttpClient(static fn (): never => throw new TransportException('Could not resolve host')));

        $this->expectException(MembershipUnknown::class);
        $this->expectExceptionMessage('GitHub could not be asked whether anna is a public member of acme-corp: Could not resolve host');
        $members->isPublicMember('acme-corp', 'anna');
    }
}
