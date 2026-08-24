<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Liberu\Ecommerce\SocialCommerce\Enums\ListingState;
use Liberu\Ecommerce\SocialCommerce\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\ListingResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\Render;
use Liberu\Ecommerce\SocialCommerce\Models\Listing;

/**
 * What this shop has put into the network, through the shop's own relation
 * rather than through a query — the relation is where four consecutive waves
 * have leaked a tenant.
 */
final class ListingsRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'listings';

    protected static ?string $title = 'Listings';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item_ref')
                    ->label('Item')
                    ->copyable()
                    ->searchable()
                    ->tooltip('Our reference, and the one a network shows. It carries none of this application\'s own ids; open it to see which subject it is.'),
                TextColumn::make('subject_ref')->label('Subject')->copyable()->searchable(),
                TextColumn::make('state')
                    ->label('Here')
                    ->badge()
                    ->formatStateUsing(fn (ListingState $state): string => Render::stateLabel($state))
                    ->color(fn (ListingState $state): string => Render::stateColour($state)),
                TextColumn::make('remote_status')
                    ->label('The network says')
                    ->badge()
                    ->state(fn (Listing $record): string => Render::remoteStatus($record->remote_status))
                    ->color(fn (Listing $record): string => Render::remoteStatusColour($record->remote_status)),
                TextColumn::make('price')
                    ->label('Price')
                    ->state(fn (Listing $record): string => Render::money($record->price())),
                TextColumn::make('last_read_at')->label('Last read')->dateTime()->placeholder(Render::NONE),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('State')
                    ->options(fn (): array => self::stateOptions()),
            ])
            ->defaultSort('item_ref')
            ->recordUrl(fn (Listing $record): string => ListingResource::getUrl('view', ['record' => $record->item_ref]))
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('Nothing has been put into this shop')
            ->emptyStateDescription('No item has been recorded for this shop, so the network holds nothing on our account.');
    }

    /** @return array<string, string> */
    private static function stateOptions(): array
    {
        $options = [];

        foreach (ListingState::cases() as $state) {
            $options[$state->value] = Render::stateLabel($state);
        }

        return $options;
    }
}
