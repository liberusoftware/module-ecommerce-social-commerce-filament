<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\SocialCommerce\Enums\ListingState;
use Liberu\Ecommerce\SocialCommerce\Enums\RemoteStatus;
use Liberu\Ecommerce\SocialCommerce\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\Pages\ListListings;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\Pages\ViewListing;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\PanelTenant;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\Render;
use Liberu\Ecommerce\SocialCommerce\Models\Listing;
use Liberu\Ecommerce\SocialCommerce\Models\Shop;
use UnitEnum;

/**
 * Every item a network is believed to hold for this merchant.
 *
 * There is no title, description, image or link column, and there cannot be:
 * the domain stores what it needs to keep the record honest — the price, the
 * stock flag and what the network reported — and not a copy of the content it
 * sent. A column claiming to show "the title we published" would be this panel
 * inventing a fact nobody wrote down.
 *
 * The route key is `item_ref` rather than the row id, so the reference a
 * network shows an operator is the reference that opens the record. Resolution
 * runs over the tenant-scoped query, so another merchant's reference and one
 * that does not exist are the same `ModelNotFoundException`.
 */
final class ListingResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    protected static ?string $model = Listing::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $recordTitleAttribute = 'item_ref';

    protected static ?string $modelLabel = 'listing';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Listings';

    protected static UnitEnum|string|null $navigationGroup = 'Social commerce';

    protected static ?int $navigationSort = 20;

    public static function getRecordRouteKeyName(): string
    {
        return 'item_ref';
    }

    /** @return Builder<Listing> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Listing> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->with('shop');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [
            HistoryRelationManager::class,
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What the network holds')
                ->columns(4)
                ->schema([
                    TextEntry::make('state')
                        ->label('Here')
                        ->badge()
                        ->state(fn (Listing $record): string => Render::stateLabel($record->state))
                        ->color(fn (Listing $record): string => Render::stateColour($record->state)),
                    TextEntry::make('remote_status')
                        ->label('The network says')
                        ->badge()
                        ->state(fn (Listing $record): string => Render::remoteStatus($record->remote_status))
                        ->color(fn (Listing $record): string => Render::remoteStatusColour($record->remote_status))
                        ->helperText('A status we do not recognise is reported as exactly that, never as still uploading.'),
                    TextEntry::make('price')
                        ->label('Price last sent')
                        ->state(fn (Listing $record): string => Render::money($record->price()))
                        ->helperText('Minor units and an ISO code. A price that could not name a currency was never published.'),
                    TextEntry::make('in_stock')
                        ->label('Stock last sent')
                        ->state(fn (Listing $record): string => Render::stock($record->in_stock))
                        ->helperText('Nothing sent yet reads as nothing sent yet, not as out of stock.'),
                    TextEntry::make('errors')
                        ->label('What the network objected to')
                        ->state(fn (Listing $record): string => Render::errors($record->errors))
                        ->columnSpanFull()
                        ->wrap(),
                ]),

            Section::make('The record')
                ->columns(4)
                ->schema([
                    TextEntry::make('item_ref')
                        ->label('Item')
                        ->copyable()
                        ->helperText('Ours, and stable for the life of the subject. It carries none of this application\'s ids, which a network would otherwise publish.'),
                    TextEntry::make('subject_ref')
                        ->label('Subject')
                        ->copyable()
                        ->helperText('Opaque here. Erasure replaces it and leaves the listing, because the network still holds the item.'),
                    TextEntry::make('shop.name')->label('Shop'),
                    TextEntry::make('remote_ref')
                        ->label('The network\'s own id')
                        ->copyable()
                        ->placeholder(Render::NONE)
                        ->helperText('Recorded, never keyed on. An identifier the other party mints is not an idempotency key.'),
                    TextEntry::make('last_transmitted_at')->label('Last sent')->dateTime()->placeholder('Never'),
                    TextEntry::make('last_read_at')->label('Last read back')->dateTime()->placeholder('Never'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item_ref')
                    ->label('Item')
                    ->copyable()
                    ->searchable()
                    ->tooltip('The reference a network shows. Search it here to find out which subject it is.'),
                TextColumn::make('subject_ref')->label('Subject')->copyable()->searchable(),
                TextColumn::make('shop.name')->label('Shop')->sortable(),
                TextColumn::make('state')
                    ->label('Here')
                    ->badge()
                    ->formatStateUsing(fn (ListingState $state): string => Render::stateLabel($state))
                    ->color(fn (ListingState $state): string => Render::stateColour($state))
                    ->sortable(),
                TextColumn::make('remote_status')
                    ->label('The network says')
                    ->badge()
                    ->state(fn (Listing $record): string => Render::remoteStatus($record->remote_status))
                    ->color(fn (Listing $record): string => Render::remoteStatusColour($record->remote_status)),
                TextColumn::make('price')
                    ->label('Price')
                    ->state(fn (Listing $record): string => Render::money($record->price())),
                TextColumn::make('in_stock')
                    ->label('Stock')
                    ->state(fn (Listing $record): string => Render::stock($record->in_stock)),
                TextColumn::make('last_read_at')->label('Last read')->dateTime()->placeholder(Render::NONE)->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->label('State')->options(fn (): array => self::stateOptions()),
                SelectFilter::make('remote_status')->label('The network says')->options(fn (): array => self::remoteStatusOptions()),
                SelectFilter::make('shop_id')
                    ->label('Shop')
                    ->options(fn (): array => Shop::query()
                        ->where('tenant_id', PanelTenant::current())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all()),
            ])
            ->defaultSort('item_ref')
            ->toolbarActions([]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
            'view' => ViewListing::route('/{record}'),
        ];
    }

    /** @return array<string, string> */
    public static function stateOptions(): array
    {
        $options = [];

        foreach (ListingState::cases() as $state) {
            $options[$state->value] = Render::stateLabel($state);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function remoteStatusOptions(): array
    {
        $options = [];

        foreach (RemoteStatus::cases() as $status) {
            $options[$status->value] = Render::remoteStatus($status);
        }

        return $options;
    }
}
