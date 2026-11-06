<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests;

use Fedale\TemplateCatalog\Git;
use PHPUnit\Framework\TestCase;

final class GitTest extends TestCase
{
    public function testParsesTheChangesOfADiff(): void
    {
        $changes = Git::parseNameStatus("A\0templates/acme/shop/1.1.0/template.yaml\0M\0vendors.yaml\0D\0templates/acme/shop/1.0.0/files/a b.png\0");

        self::assertSame(
            [['A', 'templates/acme/shop/1.1.0/template.yaml'], ['M', 'vendors.yaml'], ['D', 'templates/acme/shop/1.0.0/files/a b.png']],
            array_map(static fn ($change): array => [$change->status, $change->path], $changes),
        );
        self::assertTrue($changes[0]->isAdded());
        self::assertFalse($changes[1]->isAdded());
        self::assertSame([], Git::parseNameStatus(''));
    }
}
