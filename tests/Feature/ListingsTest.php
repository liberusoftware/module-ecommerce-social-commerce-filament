<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\SocialCommerce\Actions\ReconcileShopListings;
use Liberu\Ecommerce\SocialCommerce\Actions\RedactSubject;
use Liberu\Ecommerce\SocialCommerce\Actions\RequireWithdrawal;
use Liberu\Ecommerce\SocialCommerce\Actions\WithdrawListing;
use Liberu\Ecommerce\SocialCommerce\Enums\EventKind;
use Liberu\Ecommerce\SocialCommerce\Enums\ListingState;
use Liberu\Ecommerce\SocialCommerce\Enums\RemoteStatus;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\ListingResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\Pages\ListListings;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\Pages\ViewListing;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\Render;
use Liberu\Ecommerce\SocialCommerce\Filament\Tests\TestTenant;
use Liberu\Ecommerce\SocialCommerce\Models\Listing;
use Liberu\Ecommerce\SocialCommerce\Models\ListingEvent;
use Livewire\Livewire;

it('opens a listing by the reference a network shows, not by a row id', function (): void {
    // `item_ref` carries none of this application's ids, so it is the only
    // handle an operator arriving from a network console has.
    $shop = shop();
    $outcome = publish($shop, 'product-1');

    expect(ListingResource::getRecordRouteKeyName())->toBe('item_ref');

    Livewire::test(ViewListing::class, ['record' => $outcome->itemRef])
        ->assertSchemaStateSet(['item_ref' => $outcome->itemRef, 'subject_ref' => 'product-1']);
});

it('lists what the network is believed to hold, searchable by both references', function (): void {
    $shop = shop();
    $outcome = publish($shop, 'product-1');

    $searchable = array_map(
        fn ($column): string => $column->getName(),
        array_filter(
            Livewire::test(ListListings::class)->instance()->getTable()->getColumns(),
            fn ($column): bool => $column->isSearchable(),
        ),
    );

    expect(array_values($searchable))->toBe(['item_ref', 'subject_ref']);

    Livewire::test(ListListings::class)
        ->assertCanSeeTableRecords(Listing::query()->where('item_ref', $outcome->itemRef)->get());
});

it('records the intent and refuses the transmission when nothing is bound', function (): void {
    // The domain's principal action is unreachable by default and that is
    // structural: the record is this module's deliverable, the transmission is
    // an adapter's.
    $shop = shop();
    $outcome = publish($shop, 'product-1');

    expect($outcome->transmitted)->toBeFalse();

    Livewire::test(ViewListing::class, ['record' => $outcome->itemRef])
        ->assertSchemaStateSet([
            'state' => Render::stateLabel(ListingState::Pending),
            'remote_status' => 'Not asked',
            'price' => '19.99 GBP',
            'in_stock' => 'In stock',
        ]);
});

it('renders a price with no stored currency as nothing rather than as zero', function (): void {
    // Written straight to the table, which is what an import or a restored
    // backup does; the domain's own actions cannot produce it. A column that
    // showed `0.00` would be a public, buyable price this module never set.
    $shop = shop();
    $outcome = publish($shop, 'product-1');

    Listing::query()->where('item_ref', $outcome->itemRef)->update([
        'price_minor' => null,
        'price_currency' => null,
        'in_stock' => null,
    ]);

    Livewire::test(ViewListing::class, ['record' => $outcome->itemRef])
        ->assertSchemaStateSet(['price' => Render::NONE, 'in_stock' => Render::NONE]);
});

it('shows an unrecognised remote status as unrecognised', function (): void {
    $shop = shop();
    $outcome = publish($shop, 'product-1');

    Listing::query()->where('item_ref', $outcome->itemRef)->update(['remote_status' => RemoteStatus::Unknown->value]);

    Livewire::test(ViewListing::class, ['record' => $outcome->itemRef])
        ->assertSchemaStateSet(['remote_status' => 'Reported something we do not recognise']);
});

it('leaves a listing in the state that says it must come down when the withdrawal cannot be sent', function (): void {
    $shop = shop();
    bindChannel();
    $outcome = publish($shop, 'product-1');

    Config::set('social-commerce.seams.channel', null);

    Livewire::test(ViewListing::class, ['record' => $outcome->itemRef])
        ->callAction(TestAction::make('withdraw'));

    $listing = Listing::query()->where('item_ref', $outcome->itemRef)->firstOrFail();
    $notification = lastNotification();

    expect($listing->state)->toBe(ListingState::WithdrawalRequired)
        ->and($notification['title'])->toBe('Recorded, but not sent')
        ->and($notification['color'])->toBe('danger')
        ->and($notification['body'])->toContain('nothing is bound to talk to this network');
});

it('withdraws when the network confirms, and only then', function (): void {
    $shop = shop();
    $channel = bindChannel();
    $outcome = publish($shop, 'product-1');

    Livewire::test(ViewListing::class, ['record' => $outcome->itemRef])
        ->callAction(TestAction::make('withdraw'));

    $listing = Listing::query()->where('item_ref', $outcome->itemRef)->firstOrFail();
    $notification = lastNotification();

    expect($listing->state)->toBe(ListingState::Withdrawn)
        ->and($channel->withdrawals)->toHaveCount(1)
        ->and($channel->withdrawals[0]['itemRefs'])->toBe([$outcome->itemRef])
        ->and($notification['title'])->toBe('Withdrawn')
        ->and($notification['color'])->toBe('success');
});

it('hides the withdrawal button once the network has confirmed', function (): void {
    $shop = shop();
    bindChannel();
    $outcome = publish($shop, 'product-1');

    Livewire::test(ViewListing::class, ['record' => $outcome->itemRef])->callAction(TestAction::make('withdraw'));

    Livewire::test(ViewListing::class, ['record' => $outcome->itemRef])
        ->assertActionHidden(TestAction::make('withdraw'));
});

it('keeps every refusal in the history, where it survives the notification', function (): void {
    $shop = shop();
    $outcome = publish($shop, 'product-1');
    $listing = Listing::query()->where('item_ref', $outcome->itemRef)->firstOrFail();

    $events = Livewire::test(HistoryRelationManager::class, [
        'ownerRecord' => $listing,
        'pageClass' => ViewListing::class,
    ])->instance()->getTable()->getRecords();

    expect($events->pluck('kind')->all())
        ->toBe([EventKind::PublicationRecorded, EventKind::TransmissionRefused])
        ->and($events->last()->payload)->toBe(['reason' => 'no_channel_bound']);
});

it('shows every kind of thing that can happen to a listing, in its own words', function (): void {
    $shop = shop();

    // Refused first, with nothing bound, then sent — which is the ordinary
    // sequence for a host that installs the module before an adapter.
    $outcome = publish($shop, 'product-1');
    $channel = bindChannel();
    publish($shop, 'product-1');

    $channel->statuses = [$outcome->itemRef => remoteItem($outcome->itemRef)];
    (new ReconcileShopListings())($shop, TestTenant::PRIMARY);
    (new RequireWithdrawal())(TestTenant::PRIMARY, 'product-1', 'the variant was deleted');
    (new WithdrawListing())($shop, TestTenant::PRIMARY, $outcome->itemRef);
    (new RedactSubject())(TestTenant::PRIMARY, 'product-1');

    $listing = Listing::query()->where('item_ref', $outcome->itemRef)->firstOrFail();

    $rendered = Livewire::test(HistoryRelationManager::class, [
        'ownerRecord' => $listing,
        'pageClass' => ViewListing::class,
    ]);

    // Every kind the domain can write, each with a sentence of its own — and the
    // erasure, whose event carries no payload, rendering as nothing.
    foreach (EventKind::cases() as $kind) {
        expect($listing->events()->where('kind', $kind->value)->exists())->toBeTrue();
    }

    $rendered
        ->assertSee('We meant to publish it')
        ->assertSee('Not sent')
        ->assertSee('Sent to the network')
        ->assertSee('The network reported back')
        ->assertSee('Must be withdrawn')
        ->assertSee('The network was asked to stop')
        ->assertSee('The subject was erased');
});

it('renders a history payload it cannot flatten as nothing rather than as an array', function (): void {
    $shop = shop();
    $outcome = publish($shop, 'product-1');
    $listing = Listing::query()->where('item_ref', $outcome->itemRef)->firstOrFail();

    // Written straight to the table, which is what an import does. The domain's
    // own events carry scalars, and a column that printed `Array` would be a
    // column nobody could read.
    ListingEvent::query()->create([
        'tenant_id' => $listing->tenant_id,
        'listing_id' => $listing->id,
        'sequence' => 99,
        'kind' => EventKind::RemoteStatusRecorded->value,
        'payload' => ['errors' => ['too long', 'no image']],
        'occurred_at' => CarbonImmutable::now(),
    ]);

    $rows = Livewire::test(HistoryRelationManager::class, [
        'ownerRecord' => $listing,
        'pageClass' => ViewListing::class,
    ])->instance()->getTable()->getRecords();

    expect($rows)->toHaveCount(3);
});
