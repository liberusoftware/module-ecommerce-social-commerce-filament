# Adoption

## Install

```bash
composer require liberusoftware/ecommerce-social-commerce-filament
```

The domain package is not on Packagist yet, so this package's `composer.json` carries a `repositories`
entry pointing at its repository. That entry's presence is information: it comes out when the domain
package is published.

Nothing boots on install. `extra.laravel.providers` is absent on purpose and the host's module manager
registers the provider only when `ecommerce-social-commerce-filament` is named in `MODULES_ENABLED`.

## Attach the desk to a panel

```php
use Filament\Facades\Filament;
use Liberu\Ecommerce\SocialCommerce\Filament\SocialCommercePlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(
        SocialCommercePlugin::make()
            ->tenantUsing(fn (): string => (string) Filament::getTenant()?->getKey()),
    );
}
```

Attach it to the merchant panel and not to a shopper-facing one. Everything here is an operator
surface, and one of the screens takes a credential.

`tenantUsing()` is optional: without it the panel's own Filament tenant is used. A host whose tenancy
is not Filament's says so once, here, rather than leaving two resources and a page to guess
separately. There is no fallback to "no tenant" — `where('tenant_id', null)` compiles to `is null`,
which lists exactly the orphan rows a scope exists to hide, so an unresolvable tenant raises.

In this fleet the tenant is the `Team`. Commerce tables scope on `store_id`; this module's do not,
because a social shop is a merchant's account with a network rather than a storefront.

## What the host must bind

**Nothing, to install.** With no adapter bound the desk works completely: shops connect, listings
record, coverage counts, the withdrawal queue fills, and every publish and withdrawal reports that it
recorded the intent and refused the transmission. That is the domain's design, not a degraded mode.

**One thing, to transmit.** `Liberu\Ecommerce\SocialCommerce\Contracts\ShopChannel`, bound in the
container or named in `config('social-commerce.seams.channel')`. Each adapter is its own package;
none ships here or in the domain.

Every shop's view page says which of the two halves is missing — credentials, adapter, or neither —
before anybody presses a button.

## What it deletes from the host

Nothing yet: this package is a surface and the host's own Facebook screens are removed when the
domain module is adopted. Two host Filament surfaces have equivalents here and are superseded:

| Host | Superseded by | Why |
|---|---|---|
| `app/Filament/App/Resources/FacebookConnections/` | **Social shops** | The host's edit page deleted the credentials and stranded the listings: both unlist jobs return early when `FacebookConnection::forTeam()` is null, so the items sat at `active` forever. Here disconnecting removes the secret and nothing else, and the shop says what the network is left holding. |
| the `product_facebook_listings` read paths | **Listings**, **Must be withdrawn** | The host's row hung off `product_id` and `product_variant_id` with `cascadeOnDelete` on both, and its policy took a record and consulted only a permission name. |

Not adopted, deliberately:

- **Live chat** — `ChatConversation`, `ChatMessage`, `ChatWidget` and the chat panel resources are a
  support surface that happens to sit near the word "social".
- **Social login** — `config/socialstream.php` is identity, not commerce.
- **The Graph adapter** — `app/Services/Facebook/*` is an adapter package's work, not this wave's.
