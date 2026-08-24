<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Every relation-manager ability forced closed by name. `canAssociate()` and
 * `canDissociate()` are live on a `hasMany` and default open: dissociating a
 * listing from its shop would leave the only record of what a network holds
 * pointing at nothing, and the history table is append-only in the domain
 * precisely so it cannot be edited into agreement afterwards.
 *
 * `canViewAny()` is stated rather than inherited, so the table's visibility
 * does not rest on a policy method nobody wrote. Declared public where the
 * parent declares them protected: widening is legal, narrowing is fatal, and
 * public is what lets a test ask by name.
 */
trait DeniesUnpublishedRelationAbilities
{
    public function canViewAny(): bool
    {
        return true;
    }

    public function canAssociate(): bool
    {
        return false;
    }

    public function canAttach(): bool
    {
        return false;
    }

    public function canCreate(): bool
    {
        return false;
    }

    public function canDelete(Model $record): bool
    {
        return false;
    }

    public function canDeleteAny(): bool
    {
        return false;
    }

    public function canDetach(Model $record): bool
    {
        return false;
    }

    public function canDetachAny(): bool
    {
        return false;
    }

    public function canDissociate(Model $record): bool
    {
        return false;
    }

    public function canDissociateAny(): bool
    {
        return false;
    }

    public function canEdit(Model $record): bool
    {
        return false;
    }

    public function canForceDelete(Model $record): bool
    {
        return false;
    }

    public function canForceDeleteAny(): bool
    {
        return false;
    }

    public function canReorder(): bool
    {
        return false;
    }

    public function canReplicate(Model $record): bool
    {
        return false;
    }

    public function canRestore(Model $record): bool
    {
        return false;
    }

    public function canRestoreAny(): bool
    {
        return false;
    }

    public function canView(Model $record): bool
    {
        return false;
    }
}
