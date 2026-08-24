<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Registers a view namespace and nothing else. Every screen arrives through
 * {@see SocialCommercePlugin}, so the host decides which panels get the desk —
 * a provider that registered resources would put a credential form on a
 * shopper-facing panel.
 */
class SocialCommerceFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ecommerce-social-commerce-filament');
    }
}
