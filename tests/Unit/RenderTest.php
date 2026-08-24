<?php

declare(strict_types=1);

use Liberu\Ecommerce\SocialCommerce\Data\Money;
use Liberu\Ecommerce\SocialCommerce\Data\ReconcileOutcome;
use Liberu\Ecommerce\SocialCommerce\Data\TransmissionOutcome;
use Liberu\Ecommerce\SocialCommerce\Enums\ListingState;
use Liberu\Ecommerce\SocialCommerce\Enums\RefusalReason;
use Liberu\Ecommerce\SocialCommerce\Enums\RemoteStatus;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\Render;

/*
 * The rendering rules, without a panel. Every one of these is a sentence the
 * host got wrong somewhere.
 */

it('never renders a read that could not happen as a healthy empty run', function (): void {
    // The host printed `Reconciled 0 listing(s).` and returned SUCCESS, hourly,
    // whether the network had answered nothing or could not be reached at all.
    $unasked = ReconcileOutcome::couldNotAsk(1, 12);

    expect(Render::reconcile($unasked))->toContain('could not be asked')
        ->and(Render::reconcile($unasked))->toContain('12')
        ->and(Render::reconcileColour($unasked))->toBe('danger');
});

it('tells an empty shop apart from a shop it could not ask about', function (): void {
    $nothing = new ReconcileOutcome(1, asked: true, considered: 0);
    $unasked = ReconcileOutcome::couldNotAsk(1, 0);

    expect(Render::reconcile($nothing))->not->toBe(Render::reconcile($unasked))
        ->and(Render::reconcileColour($nothing))->toBe('success')
        ->and(Render::reconcileColour($unasked))->toBe('danger');
});

it('names the listings a network did not mention rather than folding them into a total', function (): void {
    $partial = new ReconcileOutcome(1, asked: true, considered: 10, answered: 7, silent: 3);

    expect(Render::reconcile($partial))->toContain('7 of 10')
        ->and(Render::reconcile($partial))->toContain('did not mention 3')
        ->and(Render::reconcileColour($partial))->toBe('warning');
});

it('reports a complete read as complete', function (): void {
    $complete = new ReconcileOutcome(1, asked: true, considered: 4, answered: 4);

    expect(Render::reconcile($complete))->toContain('all 4')
        ->and(Render::reconcileColour($complete))->toBe('success');
});

it('renders a price with its currency, by string arithmetic', function (): void {
    // `(int) (19.99 * 100)` is 1998, and a panel is the last place to reintroduce
    // the error the domain went to trouble to avoid.
    expect(Render::money(new Money(1999, 'GBP')))->toBe('19.99 GBP')
        ->and(Render::money(new Money(500, 'JPY', 0)))->toBe('500 JPY')
        ->and(Render::money(null))->toBe(Render::NONE);
});

it('tells a stock flag never sent apart from out of stock', function (): void {
    expect(Render::stock(null))->toBe(Render::NONE)
        ->and(Render::stock(false))->toBe('Out of stock')
        ->and(Render::stock(true))->toBe('In stock');
});

it('never renders an unrecognised remote status as still uploading', function (): void {
    // The host matched four known values and fell through to `pending`, so a
    // rejection under a label the network added last month read as uploading.
    expect(Render::remoteStatus(RemoteStatus::Unknown))->not->toContain('review')
        ->and(Render::remoteStatus(RemoteStatus::Unknown))->toContain('do not recognise')
        ->and(Render::remoteStatusColour(RemoteStatus::Unknown))->toBe('danger')
        ->and(Render::remoteStatusColour(RemoteStatus::InReview))->toBe('warning');
});

it('tells a status never asked for apart from every status a network can report', function (): void {
    $reported = array_map(Render::remoteStatus(...), RemoteStatus::cases());

    expect($reported)->not->toContain(Render::remoteStatus(null))
        ->and(count(array_unique($reported)))->toBe(count(RemoteStatus::cases()));
});

it('gives every listing state its own sentence and colour, and paints must-be-withdrawn as the loudest', function (): void {
    $labels = array_map(Render::stateLabel(...), ListingState::cases());

    expect(count(array_unique($labels)))->toBe(count(ListingState::cases()))
        ->and(Render::stateColour(ListingState::WithdrawalRequired))->toBe('danger')
        ->and(Render::stateColour(ListingState::Published))->toBe('success');
});

it('names every refusal reason in terms an operator can act on', function (): void {
    $sentences = array_map(Render::refusal(...), RefusalReason::cases());

    expect(count(array_unique($sentences)))->toBe(count(RefusalReason::cases()))
        ->and(Render::refusal(RefusalReason::NoChannelBound))->toContain('never sent')
        ->and(Render::refusal(RefusalReason::NoCredentials))->toContain('never sent');
});

it('says the record was written even when the transmission was not', function (): void {
    $refused = new TransmissionOutcome('sc_1', ListingState::Pending, transmitted: false, reason: RefusalReason::NoChannelBound, errors: ['no adapter']);

    expect(Render::transmission($refused))->toContain('Not sent')
        ->and(Render::transmission($refused))->toContain('pending')
        ->and(Render::transmission($refused))->toContain('no adapter');

    $sent = new TransmissionOutcome('sc_1', ListingState::Published, transmitted: true);

    expect(Render::transmission($sent))->toContain('Sent');
});

it('renders no errors as nothing rather than as an empty string', function (): void {
    expect(Render::errors(null))->toBe(Render::NONE)
        ->and(Render::errors([]))->toBe(Render::NONE)
        ->and(Render::errors(['too long', 'no image']))->toBe('too long; no image');
});

it('counts one of a thing without an s', function (): void {
    expect(Render::plural(1, 'listing'))->toBe('listing')
        ->and(Render::plural(0, 'listing'))->toBe('listings')
        ->and(Render::plural(2, 'listing'))->toBe('listings');
});

it('says a transmission did not happen even when nothing named a reason', function (): void {
    // `WithdrawListing` returns this shape for a listing the network has already
    // confirmed it dropped: not transmitted, and nothing refused it either.
    $neither = new TransmissionOutcome('sc_1', ListingState::Withdrawn, transmitted: false);

    expect(Render::transmission($neither))->toContain('the transmission did not happen')
        ->and(Render::transmission($neither))->toContain('withdrawn');
});
