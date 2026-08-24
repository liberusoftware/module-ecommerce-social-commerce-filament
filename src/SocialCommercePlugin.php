<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\SocialCommerce\Filament\Pages\WithdrawalQueue;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\ListingResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\ShopResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\PanelTenant;

/**
 * The social shops desk, as the host attaches it.
 *
 *     $panel->plugin(
 *         SocialCommercePlugin::make()
 *             ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
 *     );
 */
final class SocialCommercePlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'ecommerce-social-commerce';
    }

    /** How this panel names the merchant. Without it, the panel's own Filament tenant is used. */
    public function tenantUsing(?Closure $resolver): self
    {
        PanelTenant::resolveUsing($resolver);

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            ShopResource::class,
            ListingResource::class,
        ]);

        $panel->pages([
            WithdrawalQueue::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
