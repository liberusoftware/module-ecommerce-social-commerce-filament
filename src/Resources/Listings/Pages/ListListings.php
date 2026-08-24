<?php

declare(strict_types=1);

namespace Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\ListingResource;

/**
 * No header actions. A listing appears because something outside this module
 * published a subject into a shop; there is nothing here to create, and the
 * domain stores no content for this panel to re-send.
 */
final class ListListings extends ListRecords
{
    protected static string $resource = ListingResource::class;
}
