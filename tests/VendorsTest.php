<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests;

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
}
