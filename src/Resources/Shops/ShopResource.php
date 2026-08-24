<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Liberu\Ecommerce\SocialCommerce\Data\Coverage;
use Liberu\Ecommerce\SocialCommerce\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\Pages\ListShops;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\Pages\ViewShop;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\RelationManagers\HandoffsRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\RelationManagers\ListingsRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\PanelTenant;
use Liberu\Ecommerce\SocialCommerce\Models\Shop;
use Liberu\Ecommerce\SocialCommerce\Queries\ShopCoverage;
use Liberu\Ecommerce\SocialCommerce\Support\Seams;
use UnitEnum;

/**
 * One merchant's shop on one network, and what the network is believed to hold
 * for it.
 *
 * There is no secret anywhere on this screen. The domain stores it write-only —
 * reading the attribute, `toArray()` and `toJson()` all yield a placeholder —
 * and this panel offers a field that writes and never reads back. The host's
 * `FacebookConnection` had `access_token` in `$fillable` under an `encrypted`
 * cast with no `$hidden`, so the model decrypted it on attribute access and the
 * first serialiser added would have printed it.
 *
 * The network is a slug rather than a select over an enum: the domain ships no
 * `Network` type on purpose, so admitting a new network is not a release.
 */
final class ShopResource extends Resource
{
    use DeniesUnpublishedResourceAbilities;

    /** A network's own slug, as an adapter names it. Lower case, no spaces, no punctuation a URL would eat. */
    public const NETWORK_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

    protected static ?string $model = Shop::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'social shop';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Social shops';

    protected static UnitEnum|string|null $navigationGroup = 'Social commerce';

    protected static ?int $navigationSort = 10;

    /** @return Builder<Shop> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Shop> $query */
        $query = parent::getEloquentQuery();

        return $query->where('tenant_id', PanelTenant::current())->withCount('listings');
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [
            ListingsRelationManager::class,
            HandoffsRelationManager::class,
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What the network is believed to hold')
                ->description('Counted on read, per state. No count is stored anywhere: a listing is the record that somebody else holds a copy of a product, and the only truthful total is the one taken when you asked.')
                ->columns(5)
                ->schema([
                    TextEntry::make('coverage_published')
                        ->label('Live')
                        ->state(fn (Shop $record): int => self::coverage($record)->published)
                        ->helperText('Sent, and the network has not been asked to stop.'),
                    TextEntry::make('coverage_withdrawal_required')
                        ->label('Must be withdrawn')
                        ->badge()
                        ->color(fn (Shop $record): string => self::coverage($record)->withdrawalRequired > 0 ? 'danger' : 'gray')
                        ->state(fn (Shop $record): int => self::coverage($record)->withdrawalRequired)
                        ->helperText('The network still holds these and must not. Withdrawal is a state, not a delete.'),
                    TextEntry::make('coverage_pending')
                        ->label('Recorded, not sent')
                        ->state(fn (Shop $record): int => self::coverage($record)->pending)
                        ->helperText('The intent is written down. The transmission has not happened.'),
                    TextEntry::make('coverage_withdrawn')
                        ->label('Withdrawn')
                        ->state(fn (Shop $record): int => self::coverage($record)->withdrawn),
                    TextEntry::make('coverage_total')
                        ->label('Rows in total')
                        ->state(fn (Shop $record): int => self::coverage($record)->total()),
                ]),

            Section::make('Whether anything can be sent')
                ->columns(2)
                ->schema([
                    TextEntry::make('has_credentials')
                        ->label('Credentials')
                        ->badge()
                        ->state(fn (Shop $record): string => $record->isConnected() ? 'Held' : 'None')
                        ->color(fn (Shop $record): string => $record->isConnected() ? 'success' : 'danger')
                        ->helperText('Disconnecting removes the secret and nothing else, so the listings stay countable and withdrawal stays reachable once it is re-entered.'),
                    TextEntry::make('has_channel')
                        ->label('Adapter')
                        ->badge()
                        ->state(fn (): string => Seams::channel() === null ? 'Nothing bound' : 'Bound')
                        ->color(fn (): string => Seams::channel() === null ? 'danger' : 'success')
                        ->helperText('This module ships no adapter. With nothing bound, every publish records the intent and refuses the transmission — the record is the deliverable, the transmission is the adapter\'s.'),
                ]),

            Section::make('The shop')
                ->columns(4)
                ->schema([
                    TextEntry::make('name')->label('Name'),
                    TextEntry::make('network')->label('Network')->badge(),
                    TextEntry::make('external_ref')
                        ->label('The network\'s own reference')
                        ->copyable()
                        ->helperText('What the network calls this shop. Opaque here.'),
                    TextEntry::make('created_at')->label('Connected')->dateTime(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Shop')->searchable()->sortable(),
                TextColumn::make('network')->label('Network')->badge()->sortable(),
                TextColumn::make('external_ref')->label('Their reference')->copyable()->searchable(),
                IconColumn::make('has_credentials')
                    ->label('Credentials')
                    ->boolean()
                    ->state(fn (Shop $record): bool => $record->isConnected())
                    ->tooltip('Whether a secret is held. The secret itself is never readable, here or anywhere else.'),
                TextColumn::make('listings_count')
                    ->label('Listings')
                    ->sortable()
                    ->tooltip('Every row this shop has ever had, in any state. A withdrawn listing is still a row, because it is the record that the network once held the item.'),
                TextColumn::make('created_at')->label('Connected')->dateTime()->sortable(),
            ])
            ->filters([])
            ->defaultSort('created_at', 'desc')
            ->toolbarActions([]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListShops::route('/'),
            'view' => ViewShop::route('/{record}'),
        ];
    }

    /**
     * The state counts for one shop.
     *
     * ponytail: memoised per shop id, because five entries read it and each read
     * is a `group by`. Dropped when the page mounts and after every write, so no
     * figure survives the thing it counted moving.
     *
     * @var array<int, Coverage>
     */
    private static array $coverage = [];

    public static function coverage(Shop $record): Coverage
    {
        return self::$coverage[(int) $record->id] ??= (new ShopCoverage())(
            (string) $record->tenant_id,
            (int) $record->id,
        );
    }

    public static function forgetCoverage(): void
    {
        self::$coverage = [];
    }
}
