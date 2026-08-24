<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Config;
use Liberu\Ecommerce\SocialCommerce\Enums\ListingState;
use Liberu\Ecommerce\SocialCommerce\Enums\RemoteStatus;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\Pages\ListShops;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\Pages\ViewShop;
use Liberu\Ecommerce\SocialCommerce\Filament\Tests\TestTenant;
use Liberu\Ecommerce\SocialCommerce\Models\Shop;
use Liberu\Ecommerce\SocialCommerce\Models\ShopCredential;
use Livewire\Livewire;

it('lists this merchant’s shops', function (): void {
    $ours = shop();

    Livewire::test(ListShops::class)
        ->assertCanSeeTableRecords(Shop::query()->whereKey($ours->getKey())->get());
});

it('connects a shop through the domain action rather than through a form', function (): void {
    Livewire::test(ListShops::class)
        ->callAction(TestAction::make('connect'), [
            'name' => 'The shop',
            'network' => 'facebook',
            'external_ref' => 'CAT-9',
            'secret' => 'a-real-token',
        ])
        ->assertHasNoActionErrors();

    $shop = Shop::query()->where('external_ref', 'CAT-9')->firstOrFail();

    expect($shop->tenant_id)->toBe(TestTenant::PRIMARY)
        ->and($shop->network)->toBe('facebook')
        ->and($shop->isConnected())->toBeTrue();
});

it('refuses a network that is not a slug', function (): void {
    Livewire::test(ListShops::class)
        ->callAction(TestAction::make('connect'), [
            'name' => 'The shop',
            'network' => 'Facebook Shops',
            'external_ref' => 'CAT-9',
            'secret' => 'a-real-token',
        ])
        ->assertHasActionErrors(['network']);

    expect(Shop::query()->count())->toBe(0);
});

it('connects the same shop twice without making a second one', function (): void {
    $connect = fn (string $secret): mixed => Livewire::test(ListShops::class)->callAction(TestAction::make('connect'), [
        'name' => 'The shop',
        'network' => 'facebook',
        'external_ref' => 'CAT-9',
        'secret' => $secret,
    ]);

    $connect('first');
    $connect('second');

    expect(Shop::query()->count())->toBe(1)
        ->and(ShopCredential::query()->count())->toBe(1);
});

it('never puts the secret on a screen, in a table or in the model’s array form', function (): void {
    $shop = shop(secret: 'super-secret-token');

    Livewire::test(ListShops::class)->assertDontSee('super-secret-token');
    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])->assertDontSee('super-secret-token');

    $credential = ShopCredential::query()->firstOrFail();

    expect($credential->toArray())->not->toContain('super-secret-token')
        ->and($credential->secret)->toBe(ShopCredential::REDACTED)
        ->and($credential->reveal())->toBe('super-secret-token');
});

it('replaces the secret without reading the old one back', function (): void {
    $shop = shop(secret: 'first-token');

    // The form arrives empty: there is no record state to hydrate it from, so
    // the field writes and never round-trips.
    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])
        ->mountAction(TestAction::make('reconnect'))
        ->assertActionDataSet(['secret' => null]);

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])
        ->callAction(TestAction::make('reconnect'), ['secret' => 'second-token'])
        ->assertHasNoActionErrors();

    expect(ShopCredential::query()->firstOrFail()->reveal())->toBe('second-token');
});

it('shows the coverage counted on read, per state', function (): void {
    $shop = shop();
    publish($shop, 'product-1');
    publish($shop, 'product-2');

    bindChannel();
    publish($shop, 'product-3');

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])
        ->assertSchemaStateSet([
            'coverage_pending' => 2,
            'coverage_published' => 1,
            'coverage_withdrawal_required' => 0,
            'coverage_withdrawn' => 0,
            'coverage_total' => 3,
        ]);
});

it('says whether anything can be sent at all, and says so before the button is pressed', function (): void {
    $shop = shop();

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])
        ->assertSchemaStateSet(['has_credentials' => 'Held', 'has_channel' => 'Nothing bound']);

    bindChannel();

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])
        ->assertSchemaStateSet(['has_channel' => 'Bound']);
});

it('refuses to call a re-read a success when the network could not be asked', function (): void {
    $shop = shop();
    bindChannel();
    publish($shop, 'product-1');

    // Nothing bound: the read cannot happen. The host's equivalent printed
    // `Reconciled 0 listing(s).` and returned SUCCESS.
    Config::set('social-commerce.seams.channel', null);

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])->callAction(TestAction::make('reconcile'));

    expect(lastNotification())->toBe([
        'title' => 'The network could not be asked',
        'body' => 'The network could not be asked. Nothing was read and nothing was changed; 1 listing remain as they were.',
        'color' => 'danger',
    ]);
});

it('records what the network answered and reports what it did not mention', function (): void {
    $shop = shop();
    $channel = bindChannel();
    $one = publish($shop, 'product-1');
    publish($shop, 'product-2');

    $channel->statuses = [$one->itemRef => remoteItem($one->itemRef, RemoteStatus::Rejected, 'image missing')];

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])->callAction(TestAction::make('reconcile'));

    $listing = $shop->listings()->where('item_ref', $one->itemRef)->firstOrFail();

    expect($listing->remote_status)->toBe(RemoteStatus::Rejected)
        ->and($listing->errors)->toBe(['image missing'])
        ->and($listing->last_read_at)->not->toBeNull();

    expect(lastNotification())->toBe([
        'title' => 'Re-read',
        'body' => 'The network accounted for 1 of 2, and did not mention 1. Those 1 are unchanged and their status is as stale as it was.',
        'color' => 'warning',
    ]);
});

it('says a shop with nothing live has nothing to re-read, rather than nothing answered', function (): void {
    $shop = shop();
    bindChannel();

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])->callAction(TestAction::make('reconcile'));

    expect(lastNotification())->toBe([
        'title' => 'Re-read',
        'body' => 'Nothing to re-read: the network is not believed to hold anything for this shop.',
        'color' => 'success',
    ]);
});

it('disconnects by removing the secret and says what the network is left holding', function (): void {
    $shop = shop();
    bindChannel();
    publish($shop, 'product-1');
    publish($shop, 'product-2');

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])->callAction(TestAction::make('disconnect'));

    expect(ShopCredential::query()->count())->toBe(0)
        ->and($shop->listings()->where('state', ListingState::Published->value)->count())->toBe(2)
        ->and(Shop::query()->count())->toBe(1);

    expect(lastNotification())->toBe([
        'title' => 'Credentials removed',
        'body' => 'The network still holds 2 items, and cannot be asked to stop until a secret is entered again.',
        'color' => 'warning',
    ]);
});

it('hides the disconnect button on a shop with no credentials', function (): void {
    $shop = shop();

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])->callAction(TestAction::make('disconnect'));

    Livewire::test(ViewShop::class, ['record' => $shop->getKey()])
        ->assertActionHidden(TestAction::make('disconnect'));
});
