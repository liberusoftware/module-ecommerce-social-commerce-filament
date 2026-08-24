<?php

declare(strict_types=1);

use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\SocialCommerce\Actions\ConnectShop;
use Liberu\Ecommerce\SocialCommerce\Actions\PublishListing;
use Liberu\Ecommerce\SocialCommerce\Data\ListingContent;
use Liberu\Ecommerce\SocialCommerce\Data\Money;
use Liberu\Ecommerce\SocialCommerce\Data\RemoteItem;
use Liberu\Ecommerce\SocialCommerce\Data\TransmissionOutcome;
use Liberu\Ecommerce\SocialCommerce\Enums\RemoteStatus;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\ShopResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Tests\Fakes\FakeChannel;
use Liberu\Ecommerce\SocialCommerce\Filament\Tests\TestCase;
use Liberu\Ecommerce\SocialCommerce\Filament\Tests\TestTenant;
use Liberu\Ecommerce\SocialCommerce\Models\Shop;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function (): void {
        TestTenant::reset();

        // Load-bearing. The channel seam is unbound as the domain ships it, and
        // that unbound state is the behaviour half this suite is about: a test
        // inheriting a binding from the one before it would prove the opposite
        // of what it says.
        Config::set('social-commerce.seams.channel', null);

        // No count survives a test, for the same reason none survives a page
        // load: a figure carried past the thing it counted is one somebody reads
        // after it moved.
        ShopResource::forgetCoverage();
    })
    ->in(__DIR__.'/Feature');

/** A connected shop, through the published action, which is idempotent on (tenant, network, reference). */
function shop(
    string $tenantId = TestTenant::PRIMARY,
    string $externalRef = 'CAT-1',
    string $network = 'facebook',
    string $name = 'The shop',
    string $secret = 'token-1',
): Shop {
    return (new ConnectShop())($tenantId, $network, $externalRef, $name, $secret);
}

function content(
    int $minor = 1999,
    string $currency = 'GBP',
    bool $inStock = true,
    string $brand = 'A Merchant',
    string $link = 'https://merchant.example/products/thing',
): ListingContent {
    return new ListingContent(
        title: 'A Thing',
        description: 'A thing that is for sale.',
        price: new Money($minor, $currency),
        inStock: $inStock,
        link: $link,
        brand: $brand,
    );
}

/** A listing, through the published action. With nothing bound it records the intent and refuses the transmission. */
function publish(Shop $shop, string $subjectRef = 'product-1', ?ListingContent $content = null): TransmissionOutcome
{
    return (new PublishListing())($shop, (string) $shop->tenant_id, $subjectRef, $content ?? content());
}

function bindChannel(?FakeChannel $channel = null): FakeChannel
{
    $channel ??= new FakeChannel();
    Config::set('social-commerce.seams.channel', $channel);

    return $channel;
}

function remoteItem(string $itemRef, RemoteStatus $status = RemoteStatus::Approved, string ...$errors): RemoteItem
{
    return new RemoteItem($itemRef, $status, 'remote-'.$itemRef, array_values($errors));
}

/**
 * The notifications the last request sent, as title, body and colour.
 *
 * Filament's own `assertNotified()` compares the whole serialised notification,
 * so it fails on a duration or an icon this suite has no opinion about. What
 * matters here is which of the four sentences the panel chose and what colour it
 * put on it — a run that could not ask must never be green.
 *
 * Reading them consumes them, because mounting the component drains the
 * session: take one copy per assertion.
 *
 * @return array<int, array{title: ?string, body: ?string, color: mixed}>
 */
function sentNotifications(): array
{
    $component = new Notifications();
    $component->mount();

    return $component->notifications
        ->map(fn (Notification $notification): array => [
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'color' => $notification->getColor(),
        ])
        ->values()
        ->all();
}

/** @return array{title: ?string, body: ?string, color: mixed} */
function lastNotification(): array
{
    $sent = sentNotifications();

    return $sent === [] ? ['title' => null, 'body' => null, 'color' => null] : $sent[count($sent) - 1];
}
