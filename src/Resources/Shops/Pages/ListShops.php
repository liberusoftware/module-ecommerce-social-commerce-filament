<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\App;
use Liberu\Ecommerce\SocialCommerce\Actions\ConnectShop;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\ShopResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Support\PanelTenant;

/**
 * Connecting is a named action calling `ConnectShop`, not a Filament create
 * page. That is what keeps the secret out of every read path: there is no
 * record to hydrate, so the field cannot round-trip, and the resource's own
 * `canCreate()` stays closed.
 */
final class ListShops extends ListRecords
{
    protected static string $resource = ShopResource::class;

    public function mount(): void
    {
        ShopResource::forgetCoverage();

        parent::mount();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Connect a shop')
                ->icon('heroicon-o-link')
                ->modalHeading('Connect a shop on a social network')
                ->modalDescription(
                    'Registers the shop and stores the secret that reaches it. Connecting the same network and reference twice replaces the secret and does not make a second shop. '
                    .'The secret is stored write-only: it is never shown here or anywhere else, and re-entering it is the only way to change it.'
                )
                ->modalSubmitActionLabel('Connect')
                ->schema([
                    TextInput::make('name')
                        ->label('What to call it here')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('network')
                        ->label('Network')
                        ->required()
                        ->maxLength(64)
                        ->regex(ShopResource::NETWORK_PATTERN)
                        ->helperText('The adapter\'s own slug for the network — lower case letters, digits, dashes and underscores. There is no fixed list: admitting a new network is a new adapter, not a new release of this module.'),
                    TextInput::make('external_ref')
                        ->label('The network\'s reference for the shop')
                        ->required()
                        ->maxLength(255)
                        ->helperText('The catalogue or shop id the network gave you. Opaque here — this module never resolves it.'),
                    TextInput::make('secret')
                        ->label('Secret')
                        ->password()
                        ->required()
                        ->maxLength(4096)
                        ->helperText('Stored write-only. Nothing reads it back: not this screen, not an export, not the model\'s own array form.'),
                ])
                ->action(function (array $data): void {
                    /** @var array{name: string, network: string, external_ref: string, secret: string} $data */
                    $shop = App::make(ConnectShop::class)(
                        PanelTenant::current(),
                        $data['network'],
                        $data['external_ref'],
                        $data['name'],
                        $data['secret'],
                    );

                    ShopResource::forgetCoverage();

                    Notification::make()
                        ->title('Connected')
                        ->body($shop->name.' on '.$shop->network.'. Nothing has been published yet: this module ships no adapter, so a publish records the intent and refuses the transmission until a host binds one.')
                        ->color('success')
                        ->send();
                }),
        ];
    }
}
