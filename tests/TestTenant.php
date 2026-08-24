<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Tests;

/**
 * The merchant the test panel is showing. Mutable, because every custody proof
 * here gives the second merchant deliberately identical references — two
 * merchants both selling `product-1` into a shop called `CAT-1` is the ordinary
 * case, and a proof that creates one merchant's rows proves nothing.
 */
final class TestTenant
{
    public const PRIMARY = 'tenant-a';

    public const OTHER = 'tenant-b';

    private static string $current = self::PRIMARY;

    public static function current(): string
    {
        return self::$current;
    }

    public static function use(string $tenantId): void
    {
        self::$current = $tenantId;
    }

    public static function reset(): void
    {
        self::$current = self::PRIMARY;
    }
}
