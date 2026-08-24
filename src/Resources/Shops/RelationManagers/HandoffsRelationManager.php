<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\SocialCommerce\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\Render;

/**
 * Orders that arrived attributed to this shop. A record, not a lead pipeline:
 * the order reference is opaque and this module never resolves it, so there is
 * no customer, no total and no name to show — and nothing here to leak.
 */
final class HandoffsRelationManager extends RelationManager
{
    use DeniesUnpublishedRelationAbilities;

    protected static string $relationship = 'handoffs';

    protected static ?string $title = 'Orders attributed here';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_ref')
                    ->label('Order')
                    ->copyable()
                    ->searchable()
                    ->tooltip('Whatever owns orders calls it this. Recording the same reference twice records once.'),
                TextColumn::make('item_ref')
                    ->label('Item')
                    ->copyable()
                    ->placeholder(Render::NONE)
                    ->tooltip('The listing the shopper came from, when the network said which.'),
                TextColumn::make('occurred_at')->label('When')->dateTime()->sortable(),
            ])
            ->filters([])
            ->defaultSort('occurred_at', 'desc')
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateHeading('No order has been attributed to this shop')
            ->emptyStateDescription('Attribution is recorded by whatever takes the order. Nothing in this panel writes one, because inventing an order is not a desk decision.');
    }
}
