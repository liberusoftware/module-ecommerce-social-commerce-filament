<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Every ability the domain does not publish, forced closed by name. A missing
 * policy method is permissive — Filament falls through to allow — so "we wrote
 * no delete policy" is "delete is on and nobody decided".
 *
 * `canCreate` and `canEdit` are in the list on purpose. Connecting a shop is
 * `ConnectShop`, offered as a named action, so Filament's own create and edit
 * machinery is never a second way to write these rows. `canDelete` matters
 * most: a listing is the only record that a network holds an item, and
 * withdrawal is a state it has to reach rather than a row that can be dropped.
 */
trait DeniesUnpublishedResourceAbilities
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }
}
