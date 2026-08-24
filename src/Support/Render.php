<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Support;

use Liberu\Ecommerce\SocialCommerce\Data\Money;
use Liberu\Ecommerce\SocialCommerce\Data\ReconcileOutcome;
use Liberu\Ecommerce\SocialCommerce\Data\TransmissionOutcome;
use Liberu\Ecommerce\SocialCommerce\Enums\ListingState;
use Liberu\Ecommerce\SocialCommerce\Enums\RefusalReason;
use Liberu\Ecommerce\SocialCommerce\Enums\RemoteStatus;

/**
 * The sentences this panel is most likely to get wrong, written once.
 *
 * The host's reconcile command returned `SUCCESS` after printing
 * `Reconciled 0 listing(s).` for a read that never happened, hourly, because a
 * failed read and an empty one were both `[]`. Nothing here may render an
 * unasked question as a healthy answer.
 */
final class Render
{
    public const NONE = '—';

    /** A price, or the fact there is not one. Never a zero and never a bare number without its currency. */
    public static function money(?Money $money): string
    {
        return $money === null ? self::NONE : $money->decimal().' '.$money->currency;
    }

    /** Whether the item is buyable. `null` is "we have never said", which is not "out of stock". */
    public static function stock(?bool $inStock): string
    {
        return match ($inStock) {
            true => 'In stock',
            false => 'Out of stock',
            null => self::NONE,
        };
    }

    /** What the network last said, or that it has not been asked. */
    public static function remoteStatus(?RemoteStatus $status): string
    {
        return match ($status) {
            null => 'Not asked',
            RemoteStatus::Approved => 'Approved',
            RemoteStatus::Rejected => 'Rejected',
            RemoteStatus::InReview => 'In review',
            RemoteStatus::OutOfStock => 'Out of stock',
            RemoteStatus::Unknown => 'Reported something we do not recognise',
        };
    }

    /**
     * The outcome of a reconciliation, in four distinguishable sentences.
     *
     * `asked: false` is never a run that reports zero updates and succeeds, and
     * `considered: 0` is never confused with it.
     */
    public static function reconcile(ReconcileOutcome $outcome): string
    {
        if (! $outcome->asked) {
            return 'The network could not be asked. Nothing was read and nothing was changed; '
                .$outcome->considered.' '.self::plural($outcome->considered, 'listing').' remain as they were.';
        }

        if ($outcome->considered === 0) {
            return 'Nothing to re-read: the network is not believed to hold anything for this shop.';
        }

        if ($outcome->isComplete()) {
            return 'The network accounted for all '.$outcome->considered.' '.self::plural($outcome->considered, 'listing').'.';
        }

        return 'The network accounted for '.$outcome->answered.' of '.$outcome->considered.', and did not mention '
            .$outcome->silent.'. Those '.$outcome->silent.' are unchanged and their status is as stale as it was.';
    }

    /** Success, partial and could-not-ask must not share a colour. */
    public static function reconcileColour(ReconcileOutcome $outcome): string
    {
        return match (true) {
            ! $outcome->asked => 'danger',
            $outcome->considered === 0, $outcome->isComplete() => 'success',
            default => 'warning',
        };
    }

    /** Why a transmission did not happen, in a sentence an operator can act on. */
    public static function refusal(RefusalReason $reason): string
    {
        return match ($reason) {
            RefusalReason::NoChannelBound => 'nothing is bound to talk to this network, so the request was never sent',
            RefusalReason::NoCredentials => 'this shop has no credentials, so the request was never sent',
            RefusalReason::ChannelRefused => 'the network refused it',
            RefusalReason::ChannelUnreachable => 'the network could not be reached',
        };
    }

    /** The record was written either way; only the transmission is in doubt. */
    public static function transmission(TransmissionOutcome $outcome): string
    {
        if ($outcome->transmitted) {
            return 'Sent. The network now holds this item as '.$outcome->state->value.'.';
        }

        $why = $outcome->reason === null
            ? 'the transmission did not happen'
            : self::refusal($outcome->reason);

        return 'Not sent: '.$why.'. The record says '.$outcome->state->value
            .self::because($outcome->errors).'.';
    }

    /** @param  array<int, string>  $errors */
    public static function errors(?array $errors): string
    {
        return $errors === null || $errors === [] ? self::NONE : implode('; ', $errors);
    }

    public static function stateLabel(ListingState $state): string
    {
        return match ($state) {
            ListingState::Pending => 'Recorded, not sent',
            ListingState::Published => 'The network holds it',
            ListingState::WithdrawalRequired => 'Must be withdrawn',
            ListingState::Withdrawn => 'Withdrawn',
        };
    }

    public static function stateColour(ListingState $state): string
    {
        return match ($state) {
            ListingState::Pending => 'warning',
            ListingState::Published => 'success',
            // The network holds something it must not. Nothing else on this
            // panel is more urgent.
            ListingState::WithdrawalRequired => 'danger',
            ListingState::Withdrawn => 'gray',
        };
    }

    public static function remoteStatusColour(?RemoteStatus $status): string
    {
        return match ($status) {
            RemoteStatus::Approved => 'success',
            RemoteStatus::Rejected => 'danger',
            RemoteStatus::InReview => 'warning',
            RemoteStatus::OutOfStock => 'gray',
            // Unknown is not in progress. The host mapped an unrecognised value
            // to `pending`, so a rejection under a new label read as uploading.
            RemoteStatus::Unknown => 'danger',
            null => 'gray',
        };
    }

    public static function plural(int $count, string $noun): string
    {
        return $count === 1 ? $noun : $noun.'s';
    }

    /** @param  array<int, string>  $errors */
    private static function because(array $errors): string
    {
        return $errors === [] ? '' : ' ('.implode('; ', $errors).')';
    }
}
