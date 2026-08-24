<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\SocialCommerce\Actions\ConnectShop;
use Liberu\Ecommerce\SocialCommerce\Actions\DisconnectShop;
use Liberu\Ecommerce\SocialCommerce\Actions\ReconcileShopListings;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\ShopResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\PanelTenant;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\Render;
use Liberu\Ecommerce\SocialCommerce\Models\Shop;

/**
 * One shop, its coverage, and the three things a desk may do to it.
 *
 * Re-reading is the one that matters. The host's hourly command printed
 * `Reconciled 0 listing(s).` and returned success whether the network had
 * answered nothing or could not be reached at all, because both were `[]`.
 * Here the outcome is four distinguishable sentences and a run that could not
 * ask is never green.
 *
 * None of the three catches a domain exception: the record is resolved through
 * the tenant-scoped query, so the custody check inside each action cannot fail,
 * and an unexercised catch is a lying constraint.
 */
final class ViewShop extends ViewRecord
{
    protected static string $resource = ShopResource::class;

    public function mount(int|string $record): void
    {
        ShopResource::forgetCoverage();

        parent::mount($record);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reconcile')
                ->label('Re-read what the network says')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Ask the network about the items it is believed to hold')
                ->modalDescription(
                    'Reads back the status of every listing this shop has live, and records what came back. A read has three outcomes and they are reported apart: it answered, '
                    .'it answered about only some of them, or it could not be asked at all. A read that could not happen changes nothing and is not a success.'
                )
                ->action(function (Shop $record): void {
                    $outcome = App::make(ReconcileShopListings::class)($record, PanelTenant::current());

                    ShopResource::forgetCoverage();

                    Notification::make()
                        ->title($outcome->asked ? 'Re-read' : 'The network could not be asked')
                        ->body(Render::reconcile($outcome))
                        ->color(Render::reconcileColour($outcome))
                        ->persistent()
                        ->send();
                }),

            Action::make('reconnect')
                ->label('Replace the secret')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->modalHeading('Replace this shop\'s secret')
                ->modalDescription('The secret held now is not shown, because nothing reads it back. Entering a new one replaces it; the shop and every listing are untouched.')
                ->modalSubmitActionLabel('Replace')
                ->schema([
                    TextInput::make('secret')
                        ->label('New secret')
                        ->password()
                        ->required()
                        ->maxLength(4096),
                ])
                ->action(function (Shop $record, array $data): void {
                    /** @var array{secret: string} $data */
                    App::make(ConnectShop::class)(
                        PanelTenant::current(),
                        $record->network,
                        $record->external_ref,
                        $record->name,
                        $data['secret'],
                    );

                    Notification::make()->title('Secret replaced')->color('success')->send();
                }),

            Action::make('disconnect')
                ->label('Disconnect')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Remove this shop\'s credentials')
                ->modalDescription(
                    'Deletes the secret and nothing else. The shop and every listing stay, so what the network still holds stays countable — and it does still hold it. '
                    .'Nothing can be published or withdrawn until a secret is entered again.'
                )
                ->modalSubmitActionLabel('Disconnect')
                ->visible(fn (Shop $record): bool => $record->isConnected())
                ->action(function (Shop $record): void {
                    $live = App::make(DisconnectShop::class)($record, PanelTenant::current());

                    ShopResource::forgetCoverage();

                    Notification::make()
                        ->title('Credentials removed')
                        ->body($live === 0
                            ? 'The network is not believed to hold anything for this shop.'
                            : 'The network still holds '.$live.' '.Render::plural($live, 'item').', and cannot be asked to stop until a secret is entered again.')
                        ->color($live === 0 ? 'success' : 'warning')
                        ->persistent()
                        ->send();
                }),
        ];
    }
}
