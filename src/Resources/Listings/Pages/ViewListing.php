<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\SocialCommerce\Actions\WithdrawListing;
use Liberu\Ecommerce\SocialCommerce\Enums\ListingState;
use Liberu\Ecommerce\SocialCommerce\Exceptions\SocialCommerceException;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\ListingResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\PanelTenant;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\Render;
use Liberu\Ecommerce\SocialCommerce\Models\Listing;
use Liberu\Ecommerce\SocialCommerce\Queries\FindShop;

/**
 * One listing, its history, and the one thing a desk may do to it.
 *
 * Withdrawal is offered and republication is not. The domain keeps no copy of
 * the content it sent, so a re-send from here would mean this panel inventing a
 * title and a description for something a network publishes to shoppers.
 */
final class ViewListing extends ViewRecord
{
    protected static string $resource = ListingResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('withdraw')
                ->label('Ask the network to stop holding this')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Withdraw this item')
                ->modalDescription(
                    'Marks the listing as one the network must stop holding, then asks it to. If the request cannot be sent the listing stays in that state rather than being '
                    .'quietly dropped, because the network still holds the item and somebody has to be able to count it.'
                )
                ->modalSubmitActionLabel('Withdraw')
                ->hidden(fn (Listing $record): bool => $record->state === ListingState::Withdrawn)
                ->action(function (Listing $record): void {
                    $tenant = PanelTenant::current();

                    try {
                        $shop = (new FindShop())($tenant, (int) $record->shop_id);
                        $outcome = App::make(WithdrawListing::class)($shop, $tenant, (string) $record->item_ref);
                    } catch (SocialCommerceException $e) {
                        Notification::make()->title('Nothing was withdrawn')->body($e->getMessage())->color('danger')->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title($outcome->transmitted ? 'Withdrawn' : 'Recorded, but not sent')
                        ->body(Render::transmission($outcome))
                        ->color($outcome->transmitted ? 'success' : 'danger')
                        ->persistent()
                        ->send();

                    $this->refreshFormData(['state', 'remote_status', 'errors', 'last_transmitted_at']);
                }),
        ];
    }
}
