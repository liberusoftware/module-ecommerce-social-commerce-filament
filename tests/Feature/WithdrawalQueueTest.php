<?php

declare(strict_types=1);

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\SocialCommerce\Actions\DisconnectShop;
use Liberu\Ecommerce\SocialCommerce\Actions\RequireWithdrawal;
use Liberu\Ecommerce\SocialCommerce\Enums\ListingState;
use Liberu\Ecommerce\SocialCommerce\Filament\Pages\WithdrawalQueue;
use Liberu\Ecommerce\SocialCommerce\Filament\Tests\TestTenant;
use Liberu\Ecommerce\SocialCommerce\Models\Listing;
use Livewire\Livewire;

/*
 * The screen the module exists for. The host hung its record of a published
 * item off the product and the variant with `cascadeOnDelete` on both, so
 * deleting a variant deleted the only note that the network still had a live,
 * buyable item.
 */

/** A listing the network holds and must not, in a shop that can still be reached. */
function stranded(string $subjectRef = 'product-1', string $externalRef = 'CAT-1'): Listing
{
    $shop = shop(externalRef: $externalRef);
    bindChannel();
    $outcome = publish($shop, $subjectRef);
    (new RequireWithdrawal())(TestTenant::PRIMARY, $subjectRef, 'the variant was deleted');

    return Listing::query()->where('item_ref', $outcome->itemRef)->firstOrFail();
}

it('counts every item a network holds and must not, across every shop', function (): void {
    stranded('product-1', 'CAT-1');
    stranded('product-2', 'CAT-2');

    $rows = Livewire::test(WithdrawalQueue::class)->instance()->getTable()->getRecords();

    expect($rows)->toHaveCount(2)
        ->and(WithdrawalQueue::getNavigationBadge())->toBe('2')
        ->and(WithdrawalQueue::getNavigationBadgeColor())->toBe('danger');
});

it('carries no badge when no network is holding anything it should not', function (): void {
    shop();

    expect(WithdrawalQueue::getNavigationBadge())->toBeNull();
});

it('shows the row without inventing a status the network never reported', function (): void {
    $listing = stranded();

    $rows = Livewire::test(WithdrawalQueue::class)->instance()->getTable()->getRecords();
    $row = $rows->first();

    expect($row['item_ref'])->toBe($listing->item_ref)
        ->and($row['subject_ref'])->toBe('product-1')
        ->and($row['shop'])->toBe('The shop')
        ->and($row['remote_status'])->toBe('Not asked')
        ->and($row['errors'])->toBe('—');
});

it('sweeps the queue by one published withdrawal per row', function (): void {
    stranded('product-1', 'CAT-1');
    stranded('product-2', 'CAT-2');
    $channel = bindChannel();

    Livewire::test(WithdrawalQueue::class)->callAction(TestAction::make('withdrawAll'));

    $notification = lastNotification();

    expect($channel->withdrawals)->toHaveCount(2)
        ->and(Listing::query()->where('state', ListingState::Withdrawn->value)->count())->toBe(2)
        ->and($notification['title'])->toBe('Withdrawn')
        ->and($notification['body'])->toBe('2 of 2 items came down.')
        ->and($notification['color'])->toBe('success');
});

it('never reports a sweep that sent nothing as a sweep that worked', function (): void {
    // The whole point. With nothing bound, every withdrawal is refused, every
    // row stays on the queue, and the run says so in red.
    stranded('product-1', 'CAT-1');
    stranded('product-2', 'CAT-2');
    Config::set('social-commerce.seams.channel', null);

    Livewire::test(WithdrawalQueue::class)->callAction(TestAction::make('withdrawAll'));

    $notification = lastNotification();

    expect(Listing::query()->where('state', ListingState::WithdrawalRequired->value)->count())->toBe(2)
        ->and($notification['title'])->toBe('Some are still up')
        ->and($notification['body'])->toBe('0 of 2 items came down. 2 still up because nothing is bound to talk to this network, so the request was never sent.')
        ->and($notification['color'])->toBe('danger');
});

it('names what it could not withdraw when only some shops can be reached', function (): void {
    $kept = stranded('product-1', 'CAT-1');
    stranded('product-2', 'CAT-2');

    // One shop's credentials are gone, which the host made permanent: both its
    // jobs returned early with no connection, so the listings sat at active
    // forever.
    (new DisconnectShop())($kept->shop()->firstOrFail(), TestTenant::PRIMARY);
    bindChannel();

    Livewire::test(WithdrawalQueue::class)->callAction(TestAction::make('withdrawAll'));

    $notification = lastNotification();

    expect($notification['title'])->toBe('Some are still up')
        ->and($notification['body'])->toBe('1 of 2 items came down. 1 still up because this shop has no credentials, so the request was never sent.')
        ->and($notification['color'])->toBe('warning')
        ->and(Listing::query()->where('state', ListingState::WithdrawalRequired->value)->count())->toBe(1);
});

it('says there is nothing to withdraw rather than that it withdrew nothing', function (): void {
    shop();
    bindChannel();

    Livewire::test(WithdrawalQueue::class)->callAction(TestAction::make('withdrawAll'));

    $notification = lastNotification();

    expect($notification['title'])->toBe('Nothing to withdraw')
        ->and($notification['color'])->toBe('success');
});

it('empties a row from the queue only when the network confirms', function (): void {
    stranded();
    $channel = bindChannel();
    $channel->accept = false;
    $channel->message = 'catalogue is locked';

    Livewire::test(WithdrawalQueue::class)->callAction(TestAction::make('withdrawAll'));

    $rows = Livewire::test(WithdrawalQueue::class)->instance()->getTable()->getRecords();

    expect($rows)->toHaveCount(1);
});
