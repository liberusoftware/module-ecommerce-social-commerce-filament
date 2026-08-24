<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Pages;

use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\SocialCommerce\Actions\WithdrawListing;
use Liberu\Ecommerce\SocialCommerce\Exceptions\SocialCommerceException;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\PanelTenant;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\Render;
use Liberu\Ecommerce\SocialCommerce\Models\Listing;
use Liberu\Ecommerce\SocialCommerce\Models\Shop;
use Liberu\Ecommerce\SocialCommerce\Queries\FindShop;
use Liberu\Ecommerce\SocialCommerce\Queries\ListListings;
use UnitEnum;

/**
 * Everything a network is believed to hold and must not.
 *
 * This is the screen the module exists for. The host's schema hung its record
 * of a published item off the product and the variant with `cascadeOnDelete` on
 * both, so deleting a variant deleted the only note that Meta still had a live,
 * buyable item — and disconnecting the credentials made unlisting unreachable,
 * because both jobs returned early with no connection. A record of something
 * held by a third party is not a cache of local state, so withdrawal is a state
 * to reach, and this queue is where the unreached ones are counted.
 *
 * Nothing here decides anything. The rows are `ListListings::awaitingWithdrawal`
 * and the sweep is `WithdrawListing` once per row.
 */
final class WithdrawalQueue extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Must be withdrawn';

    protected static UnitEnum|string|null $navigationGroup = 'Social commerce';

    protected static ?int $navigationSort = 30;

    protected static ?string $title = 'Items a network still holds and must not';

    protected static ?string $slug = 'social-commerce/withdrawal-queue';

    protected string $view = 'ecommerce-social-commerce-filament::pages.withdrawal-queue';

    public static function getNavigationBadge(): ?string
    {
        $count = (new ListListings())->awaitingWithdrawal(PanelTenant::current())->count();

        return $count === 0 ? null : (string) $count;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    public function getSubheading(): string
    {
        return 'Each of these is live on a network on this merchant\'s account, and something has said it must not be. '
            .'A row leaves this queue when the network confirms it has stopped holding the item, and by no other means: '
            .'nothing in this module deletes one, because deleting it would leave the item live and unrecorded.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->awaiting())
            ->columns([
                TextColumn::make('shop')->label('Shop'),
                TextColumn::make('item_ref')
                    ->label('Item')
                    ->copyable()
                    ->tooltip('Search this reference on the Listings screen to open the record and withdraw it on its own.'),
                TextColumn::make('subject_ref')->label('Subject')->copyable(),
                TextColumn::make('remote_status')->label('The network last said'),
                TextColumn::make('errors')->label('What it objected to')->wrap(),
                // The row's own last change, which is when withdrawal became
                // required unless a re-read has touched it since.
                TextColumn::make('since')->label('Last changed'),
            ])
            ->paginated(false)
            // A queue that should be empty, in the order it was filled. A filter
            // on it is a way to stop looking at something.
            ->filters([])
            // Array records: the row URL and action are typed against a Model.
            ->recordUrl(null)
            ->recordAction(null)
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('No network is holding anything it should not')
            ->emptyStateDescription('Every item that had to come down has come down, and the network confirmed each one.');
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('withdrawAll')
                ->label('Withdraw everything on this queue')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Ask every network to stop holding these items')
                ->modalDescription(
                    'One withdrawal per row, in order. A row the network confirms leaves the queue; a row it refuses stays on it and is named below, '
                    .'so a sweep that sent nothing cannot read as a sweep that worked.'
                )
                ->modalSubmitActionLabel('Withdraw them')
                ->action(fn (): null => $this->withdrawAll()),
        ];
    }

    /**
     * One pass over the domain query, one published action per row.
     *
     * ponytail: unpaginated and sequential, which is what an operator pressing a
     * button expects to be able to read the result of. If a merchant ever has
     * enough of these for it to matter, the sweep belongs in a queued job, not in
     * a cleverer loop.
     */
    private function withdrawAll(): null
    {
        $tenant = PanelTenant::current();
        $withdraw = App::make(WithdrawListing::class);
        $find = new FindShop();

        /** @var array<int, Shop> $shops */
        $shops = [];
        $withdrawn = 0;
        $considered = 0;

        /** @var array<string, int> $refused */
        $refused = [];

        foreach ((new ListListings())->awaitingWithdrawal($tenant) as $listing) {
            $considered++;

            try {
                $shop = $shops[(int) $listing->shop_id] ??= $find($tenant, (int) $listing->shop_id);
                $outcome = $withdraw($shop, $tenant, (string) $listing->item_ref);
            } catch (SocialCommerceException) {
                // A row naming a shop this merchant does not have. The domain's
                // refusal is deliberately the same for a shop that is somebody
                // else's and one that does not exist, so neither is repeated
                // back to an operator.
                $reason = 'the listing names a shop this merchant does not have';
                $refused[$reason] = ($refused[$reason] ?? 0) + 1;

                continue;
            }

            if ($outcome->transmitted) {
                $withdrawn++;

                continue;
            }

            $reason = $outcome->reason === null ? 'the transmission did not happen' : Render::refusal($outcome->reason);
            $refused[$reason] = ($refused[$reason] ?? 0) + 1;
        }

        $this->report($considered, $withdrawn, $refused);

        return null;
    }

    /** @param  array<string, int>  $refused */
    private function report(int $considered, int $withdrawn, array $refused): void
    {
        if ($considered === 0) {
            Notification::make()
                ->title('Nothing to withdraw')
                ->body('No network is holding anything for this merchant that it should not.')
                ->color('success')
                ->send();

            return;
        }

        $body = $withdrawn.' of '.$considered.' '.Render::plural($considered, 'item').' came down.';

        foreach ($refused as $reason => $count) {
            $body .= ' '.$count.' still up because '.$reason.'.';
        }

        Notification::make()
            ->title($refused === [] ? 'Withdrawn' : 'Some are still up')
            ->body($body)
            ->color($refused === [] ? 'success' : ($withdrawn === 0 ? 'danger' : 'warning'))
            ->persistent()
            ->send();
    }

    /** @return Collection<int, array{shop: string, item_ref: string, subject_ref: string, remote_status: string, errors: string, since: string}> */
    private function awaiting(): Collection
    {
        $listings = (new ListListings())->awaitingWithdrawal(PanelTenant::current());
        $listings->load('shop');

        return $listings->map(fn (Listing $listing): array => [
            'shop' => $listing->shop->name ?? Render::NONE,
            'item_ref' => $listing->item_ref,
            'subject_ref' => $listing->subject_ref,
            'remote_status' => Render::remoteStatus($listing->remote_status),
            'errors' => Render::errors($listing->errors),
            'since' => self::changedAt($listing),
        ])->values();
    }

    /** Through `getAttribute`, because the domain model's property list does not name its timestamps. */
    private static function changedAt(Listing $listing): string
    {
        $changed = $listing->getAttribute('updated_at');

        return $changed instanceof CarbonInterface ? $changed->toDayDateTimeString() : Render::NONE;
    }
}
