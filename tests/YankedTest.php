<?php

declare(strict_types=1);

namespace Fedale\TemplateCatalog\Tests;

use Fedale\TemplateCatalog\Yanked;
use PHPUnit\Framework\TestCase;

final class YankedTest extends TestCase
{
    public function testListsTheYankedVersions(): void
    {
        $yanked = Yanked::fromYaml("acme/shop: [1.0.1, '1.0.2']\nacme/geo-italy: []\n");

        self::assertTrue($yanked->has('acme/shop', '1.0.1'));
        self::assertTrue($yanked->has('acme/shop', '1.0.2'));
        self::assertFalse($yanked->has('acme/shop', '1.0.0'));
        self::assertSame([['acme/shop', '1.0.1'], ['acme/shop', '1.0.2']], $yanked->all());
        self::assertSame([], Yanked::fromYaml("# none\n")->all());
    }

    public function testTellsWhatAChangeYanksOrListsAgain(): void
    {
        $before = Yanked::fromYaml("acme/shop: [1.0.1, 1.0.2]\n");
        $after = Yanked::fromYaml("acme/shop: [1.0.2, 1.1.0]\n");

        self::assertSame([['acme/shop', '1.1.0'], ['acme/shop', '1.0.1']], $after->differences($before));
        self::assertSame([], $after->differences($after));
    }

    public function testAllRemovesATemplate(): void
    {
        $yanked = Yanked::fromYaml("acme/shop: all\nacme/geo-italy: [1.0.0]\n");

        self::assertTrue($yanked->isRemoved('acme/shop'));
        self::assertTrue($yanked->has('acme/shop', '1.0.0'));
        self::assertTrue($yanked->has('acme/shop', '9.9.9'));
        self::assertFalse($yanked->isRemoved('acme/geo-italy'));
        self::assertSame(['acme/shop'], $yanked->removed());
        // A removal, and a template listed again, are changes of their own.
        self::assertSame([['acme/shop', 'all']], $yanked->differences(Yanked::fromYaml("acme/geo-italy: [1.0.0]\n")));
        self::assertSame([['acme/shop', 'all']], Yanked::fromYaml("acme/geo-italy: [1.0.0]\n")->differences($yanked));
    }

    public function testRefusesSomethingThatIsNotAVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('acme/shop lists something that is not a version');

        Yanked::fromYaml("acme/shop: [1.0]\n");
    }
}
