<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Liberu\Ecommerce\SocialCommerce\Actions\RecordCheckoutHandoff;
use Liberu\Ecommerce\SocialCommerce\Actions\RequireWithdrawal;
use Liberu\Ecommerce\SocialCommerce\Filament\Pages\WithdrawalQueue;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\Pages\ListListings;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\Pages\ViewListing;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\Pages\ListShops;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\Pages\ViewShop;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\RelationManagers\HandoffsRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\RelationManagers\ListingsRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\ShopResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\PanelTenant;
use Liberu\Ecommerce\SocialCommerce\Filament\Tests\TestTenant;
use Liberu\Ecommerce\SocialCommerce\Models\Listing;
use Liberu\Ecommerce\SocialCommerce\Models\Shop;
use Livewire\Livewire;

/*
 * Two merchants holding deliberately identical references: the same network,
 * the same catalogue id, the same subject, the same order. Two merchants both
 * selling `product-1` into a shop the network calls `CAT-1` is the ordinary
 * case, and a proof that creates one merchant's rows proves nothing about a
 * `where` clause nobody wrote.
 *
 * Every relation on every screen is exercised, not only every list: tenancy has
 * leaked through relations rather than queries in four consecutive waves, and
 * the host's own version of this module scoped its credential table with exactly
 * one explicit `where` and its listing table with nothing at all.
 */

/** @return array{ours: Shop, theirs: Shop, mine: string, yours: string} */
function twoMerchants(): array
{
    bindChannel();

    $ours = shop(TestTenant::PRIMARY, 'CAT-1', 'facebook', 'The shop');
    $theirs = shop(TestTenant::OTHER, 'CAT-1', 'facebook', 'The shop');

    $mine = publish($ours, 'product-1')->itemRef;
    $yours = publish($theirs, 'product-1')->itemRef;

    (new RecordCheckoutHandoff())($ours, TestTenant::PRIMARY, 'order-1', $mine);
    (new RecordCheckoutHandoff())($theirs, TestTenant::OTHER, 'order-1', $yours);

    return ['ours' => $ours, 'theirs' => $theirs, 'mine' => $mine, 'yours' => $yours];
}

it('lists only this merchant’s shops and listings', function (): void {
    $rows = twoMerchants();

    Livewire::test(ListShops::class)
        ->assertCanSeeTableRecords(Shop::query()->whereKey($rows['ours']->getKey())->get())
        ->assertCanNotSeeTableRecords(Shop::query()->whereKey($rows['theirs']->getKey())->get());

    Livewire::test(ListListings::class)
        ->assertCanSeeTableRecords(Listing::query()->where('item_ref', $rows['mine'])->get())
        ->assertCanNotSeeTableRecords(Listing::query()->where('item_ref', $rows['yours'])->get());
});

it('counts this merchant’s listings through the relation, and gets the right non-zero number', function (): void {
    $rows = twoMerchants();

    publish($rows['theirs'], 'product-2');
    publish($rows['theirs'], 'product-3');

    $counted = ShopResource::getEloquentQuery()->firstOrFail();

    // `withCount()` builds the relation from a fresh instance whose `tenant_id`
    // is null. Unguarded, the restatement becomes `where('tenant_id', '')` and
    // reports zero for everything, which looks exactly like isolation working.
    expect((int) $counted->id)->toBe((int) $rows['ours']->id)
        ->and($counted->listings_count)->toBe(1);
});

it('shows only this shop’s listings and only this shop’s orders, through its own relations', function (): void {
    $rows = twoMerchants();

    $listings = Livewire::test(ListingsRelationManager::class, [
        'ownerRecord' => $rows['ours'],
        'pageClass' => ViewShop::class,
    ])->instance()->getTable()->getRecords();

    $handoffs = Livewire::test(HandoffsRelationManager::class, [
        'ownerRecord' => $rows['ours'],
        'pageClass' => ViewShop::class,
    ])->instance()->getTable()->getRecords();

    expect($listings)->toHaveCount(1)
        ->and($listings->first()->item_ref)->toBe($rows['mine'])
        ->and($handoffs)->toHaveCount(1)
        ->and($handoffs->first()->order_ref)->toBe('order-1')
        ->and((string) $handoffs->first()->tenant_id)->toBe(TestTenant::PRIMARY);
});

it('shows only this listing’s history', function (): void {
    $rows = twoMerchants();
    $mine = Listing::query()->where('item_ref', $rows['mine'])->firstOrFail();

    $events = Livewire::test(HistoryRelationManager::class, [
        'ownerRecord' => $mine,
        'pageClass' => ViewListing::class,
    ])->instance()->getTable()->getRecords();

    // Recorded, then transmitted — and not the other merchant's identical pair.
    expect($events)->toHaveCount(2)
        ->and($events->pluck('tenant_id')->unique()->all())->toBe([TestTenant::PRIMARY]);
});

it('counts only this merchant’s coverage, though both merchants have one listing', function (): void {
    $rows = twoMerchants();

    publish($rows['theirs'], 'product-2');

    expect(ShopResource::coverage($rows['ours'])->total())->toBe(1)
        ->and(ShopResource::coverage($rows['ours'])->published)->toBe(1);
});

it('queues only this merchant’s withdrawals', function (): void {
    $rows = twoMerchants();

    (new RequireWithdrawal())(TestTenant::PRIMARY, 'product-1');
    (new RequireWithdrawal())(TestTenant::OTHER, 'product-1');

    $queued = Livewire::test(WithdrawalQueue::class)->instance()->getTable()->getRecords();

    expect($queued)->toHaveCount(1)
        ->and($queued->first()['item_ref'])->toBe($rows['mine'])
        ->and(WithdrawalQueue::getNavigationBadge())->toBe('1');
});

it('answers another merchant’s shop exactly as it answers nobody’s', function (): void {
    $rows = twoMerchants();

    // Not a 403 and not a different message: the panel is not a directory of
    // the deployment, so "belongs to somebody else" and "does not exist" are
    // one answer.
    $open = fn (int|string $key): mixed => Livewire::test(ViewShop::class, ['record' => $key]);

    expect(fn (): mixed => $open($rows['theirs']->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $open(999999))->toThrow(ModelNotFoundException::class);
});

it('answers another merchant’s item reference exactly as it answers nobody’s', function (): void {
    $rows = twoMerchants();

    $open = fn (string $itemRef): mixed => Livewire::test(ViewListing::class, ['record' => $itemRef]);

    expect(fn (): mixed => $open($rows['yours']))->toThrow(ModelNotFoundException::class)
        ->and(fn (): mixed => $open('sc_nothingatall'))->toThrow(ModelNotFoundException::class);
});

it('refuses to resolve a panel with no merchant rather than matching orphan rows', function (): void {
    // `where('tenant_id', null)` compiles to `is null`, which lists exactly the
    // orphan rows a scope exists to hide.
    PanelTenant::resolveUsing(fn (): ?string => null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): string => '');

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): string => TestTenant::current());
});

it('follows the panel’s merchant when it changes, rather than the first one it saw', function (): void {
    $rows = twoMerchants();

    TestTenant::use(TestTenant::OTHER);
    ShopResource::forgetCoverage();

    Livewire::test(ListShops::class)
        ->assertCanSeeTableRecords(Shop::query()->whereKey($rows['theirs']->getKey())->get())
        ->assertCanNotSeeTableRecords(Shop::query()->whereKey($rows['ours']->getKey())->get());
});

it('refuses to act on a listing naming a shop this merchant does not have', function (): void {
    // What a bad import or a restored backup leaves behind. The row is this
    // merchant's; the shop it names is not.
    $rows = twoMerchants();

    Listing::query()->where('item_ref', $rows['mine'])->update(['shop_id' => $rows['theirs']->getKey()]);

    Livewire::test(ViewListing::class, ['record' => $rows['mine']])
        ->callAction(TestAction::make('withdraw'));

    $notification = lastNotification();

    expect($notification['title'])->toBe('Nothing was withdrawn')
        ->and($notification['color'])->toBe('danger');
});

it('names, and does not act on, a queued row whose shop is another merchant’s', function (): void {
    $rows = twoMerchants();

    (new RequireWithdrawal())(TestTenant::PRIMARY, 'product-1');
    Listing::query()->where('item_ref', $rows['mine'])->update(['shop_id' => $rows['theirs']->getKey()]);

    Livewire::test(WithdrawalQueue::class)->callAction(TestAction::make('withdrawAll'));

    $notification = lastNotification();

    expect($notification['title'])->toBe('Some are still up')
        ->and($notification['body'])->toBe('0 of 1 item came down. 1 still up because the listing names a shop this merchant does not have.')
        ->and($notification['color'])->toBe('danger');
});

it('falls back to the panel’s own tenant when the host names no resolver, and raises when there is none', function (): void {
    // A panel with no Filament tenancy and no resolver has no merchant to be.
    // There is no "show everything" to fall back to.
    PanelTenant::resolveUsing(null);

    expect(fn (): string => PanelTenant::current())->toThrow(RuntimeException::class);

    PanelTenant::resolveUsing(fn (): string => TestTenant::current());
});
