<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests;

use Fedale\TemplateCatalog\Owners\Maintainers;
use Fedale\TemplateCatalog\Vendors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VendorsTest extends TestCase
{
    public function testListsTheOwnersOfEachVendor(): void
    {
        $vendors = Vendors::fromYaml("acme:\n  owners: [Mario-Rossi, giulia]\nwidgets-co:\n  owners: [anna]\n");

        self::assertTrue($vendors->has('acme'));
        self::assertTrue($vendors->has('widgets-co'));
        self::assertFalse($vendors->has('globex'));
        // GitHub logins are case-insensitive.
        self::assertTrue($vendors->isOwner('acme', 'mario-rossi'));
        self::assertTrue($vendors->isOwner('acme', 'Giulia'));
        self::assertFalse($vendors->isOwner('acme', 'anna'));
        self::assertFalse($vendors->isOwner('globex', 'anna'));
    }

    public function testAnEmptyFileHasNoVendors(): void
    {
        self::assertFalse(Vendors::fromYaml('')->has('acme'));
    }

    #[DataProvider('invalidFiles')]
    public function testRefusesAnInvalidFile(string $yaml, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Vendors::fromYaml($yaml);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'not YAML' => ["acme: [\n", 'vendors.yaml is not valid YAML'];
        yield 'a list' => ['- acme', '"0" is not a vendor name'];
        yield 'an uppercase vendor' => ["Acme:\n  owners: [mario]\n", '"Acme" is not a vendor name'];
        yield 'no owners' => ["acme:\n  owners: []\n", 'acme needs a list of owners'];
        yield 'a team' => ["acme:\n  owners: [acme/developers]\n", 'acme has an owner that is not a GitHub login'];
    }

    public function testAVendorMayBeVerified(): void
    {
        $vendors = Vendors::fromYaml("acme:\n  owners: [mario-rossi, acme-corp]\n  verified: true\nglobex:\n  owners: [anna]\n");

        self::assertTrue($vendors->isVerified('acme'));
        self::assertFalse($vendors->isVerified('globex'));
        self::assertSame(['mario-rossi', 'acme-corp'], $vendors->owners('acme'));
        self::assertSame(['acme', 'globex'], $vendors->verificationChanges(Vendors::fromYaml("globex:\n  owners: [anna]\n  verified: true\n")));
        self::assertSame([], $vendors->verificationChanges($vendors));
    }

    public function testRefusesAnUnknownSetting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vendors.yaml: acme: "owner" is not a setting of a vendor (owners, verified).');

        Vendors::fromYaml("acme:\n  owners: [mario-rossi]\n  owner: anna\n");
    }

    public function testTheMaintainersAreTheUsersOfCodeowners(): void
    {
        $maintainers = Maintainers::fromCodeowners("# Reviews\n* @Fedale @acme/reviewers\n/docs/ @giulia-bianchi # docs\n");

        self::assertTrue($maintainers->has('fedale'));
        self::assertTrue($maintainers->has('giulia-bianchi'));
        // Teams are left out.
        self::assertFalse($maintainers->has('acme/reviewers'));
        self::assertFalse($maintainers->has('reviewers'));
        self::assertFalse(Maintainers::fromCodeowners('')->has('fedale'));
    }
}
