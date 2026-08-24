<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\SocialCommerce\Enums\EventKind;
use Liberu\Ecommerce\SocialCommerce\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\Render;
use Liberu\Ecommerce\SocialCommerce\Models\ListingEvent;

/**
 * Everything that has happened to this listing, in sequence.
 *
 * Append-only in the domain, enforced in model hooks and arbitrated by a unique
 * `(listing, sequence)` index — so nothing here edits, deletes or dissociates a
 * row. This is the only place a refused transmission is visible after the
 * notification has gone.
 */
final class HistoryRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'events';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sequence')->label('#'),
                TextColumn::make('kind')
                    ->label('What happened')
                    ->badge()
                    ->formatStateUsing(fn (EventKind $state): string => self::kindLabel($state))
                    ->color(fn (EventKind $state): string => self::kindColour($state)),
                TextColumn::make('payload')
                    ->label('Detail')
                    ->state(fn (ListingEvent $record): string => self::payload($record))
                    ->wrap(),
                TextColumn::make('occurred_at')->label('When')->dateTime(),
            ])
            ->filters([])
            ->paginated(false)
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has happened to this listing')
            ->emptyStateDescription('Not even a recorded intent to publish, which should be impossible: a listing exists because one was written.');
    }

    private static function kindLabel(EventKind $kind): string
    {
        return match ($kind) {
            EventKind::PublicationRecorded => 'We meant to publish it',
            EventKind::Transmitted => 'Sent to the network',
            EventKind::TransmissionRefused => 'Not sent',
            EventKind::WithdrawalRequired => 'Must be withdrawn',
            EventKind::Withdrawn => 'The network was asked to stop',
            EventKind::RemoteStatusRecorded => 'The network reported back',
            EventKind::SubjectRedacted => 'The subject was erased',
        };
    }

    private static function kindColour(EventKind $kind): string
    {
        return match ($kind) {
            EventKind::Transmitted, EventKind::Withdrawn => 'success',
            EventKind::TransmissionRefused, EventKind::WithdrawalRequired => 'danger',
            EventKind::RemoteStatusRecorded => 'info',
            default => 'gray',
        };
    }

    private static function payload(ListingEvent $record): string
    {
        $payload = $record->payload;

        if (! is_array($payload) || $payload === []) {
            return Render::NONE;
        }

        $parts = [];

        foreach ($payload as $key => $value) {
            $parts[] = $key.': '.(is_scalar($value) ? (string) $value : Render::NONE);
        }

        return implode(', ', $parts);
    }
}
