<?php

declare(strict_types=1);

use Liberu\Ecommerce\SocialCommerce\Filament\Concerns\DeniesUnpublishedRelationAbilities;
use Liberu\Ecommerce\SocialCommerce\Filament\Concerns\DeniesUnpublishedResourceAbilities;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\ListingResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\RelationManagers\HistoryRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\RelationManagers\HandoffsRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\RelationManagers\ListingsRelationManager;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\ShopResource;
use Liberu\Ecommerce\SocialCommerce\Models\Listing;

/*
 * The ability matrix, asserted by name. A missing policy is permissive, and a
 * policy that exists but lacks the method asked about is also permissive,
 * because Filament falls through to allow — so the only way to know an ability
 * is closed is to ask it and be told no.
 */

// Nested rows deliberately: `[ClassA::class, ClassB::class]` is PHP's callable
// array syntax, and Pest calls it instead of iterating it.
$resources = [
    [ShopResource::class],
    [ListingResource::class],
];

$managers = [
    [ListingsRelationManager::class],
    [HandoffsRelationManager::class],
    [HistoryRelationManager::class],
];

it('closes every destructive resource ability by name', function (string $resource): void {
    // A listing is the only record that a network holds an item. Deleting it
    // leaves the item live and unrecorded, which is the fault the whole module
    // is shaped around: the host cascaded that row away with the variant.
    $record = new Listing();

    expect($resource::canDelete($record))->toBeFalse()
        ->and($resource::canDeleteAny())->toBeFalse()
        ->and($resource::canForceDelete($record))->toBeFalse()
        ->and($resource::canForceDeleteAny())->toBeFalse()
        ->and($resource::canReorder())->toBeFalse()
        ->and($resource::canReplicate($record))->toBeFalse()
        ->and($resource::canRestore($record))->toBeFalse()
        ->and($resource::canRestoreAny())->toBeFalse();
})->with($resources);

it('publishes no Filament create and no Filament edit, because every write here is a domain action', function (string $resource): void {
    // Connecting a shop is `ConnectShop` behind a named action. A create page
    // would be a second way in — and an edit form would hydrate the secret out
    // of the record to put it in a field, which is the one thing the domain
    // stores write-only to prevent.
    $record = new Listing();

    expect($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($record))->toBeFalse();
})->with($resources);

it('registers no create or edit page on any resource', function (string $resource): void {
    expect(array_keys($resource::getPages()))->toBe(['index', 'view']);
})->with($resources);

it('closes every relation-manager ability by name, including associate and dissociate', function (string $manager): void {
    /** @var object $instance */
    $instance = new $manager();
    $record = new Listing();

    // `canAssociate` and `canDissociate` are live on a `hasMany` and default
    // open. Dissociating a listing from its shop leaves the record of what a
    // network holds pointing at nothing, and the history is append-only in the
    // domain precisely so it cannot be edited into agreement afterwards.
    expect($instance->canAssociate())->toBeFalse()
        ->and($instance->canDissociate($record))->toBeFalse()
        ->and($instance->canDissociateAny())->toBeFalse()
        ->and($instance->canAttach())->toBeFalse()
        ->and($instance->canDetach($record))->toBeFalse()
        ->and($instance->canDetachAny())->toBeFalse()
        ->and($instance->canCreate())->toBeFalse()
        ->and($instance->canEdit($record))->toBeFalse()
        ->and($instance->canDelete($record))->toBeFalse()
        ->and($instance->canDeleteAny())->toBeFalse()
        ->and($instance->canForceDelete($record))->toBeFalse()
        ->and($instance->canForceDeleteAny())->toBeFalse()
        ->and($instance->canReorder())->toBeFalse()
        ->and($instance->canReplicate($record))->toBeFalse()
        ->and($instance->canRestore($record))->toBeFalse()
        ->and($instance->canRestoreAny())->toBeFalse()
        ->and($instance->canView($record))->toBeFalse()
        // The one ability published, stated rather than inherited.
        ->and($instance->canViewAny())->toBeTrue();
})->with($managers);

it('names no method after a Filament ability outside the two concerns that close them', function (string $class): void {
    // A subclass method wins over a trait's, so a method named for an ability
    // would silently reopen it.
    $abilities = array_merge(
        get_class_methods(DeniesUnpublishedResourceAbilities::class),
        get_class_methods(DeniesUnpublishedRelationAbilities::class),
    );

    // A trait's methods report the using class as their declaring class, so the
    // file is what separates "this class wrote it" from "the trait did".
    $reflection = new ReflectionClass($class);
    $file = $reflection->getFileName();

    $declared = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $reflection->getMethods(),
            fn (ReflectionMethod $method): bool => $method->getFileName() === $file,
        ),
    );

    expect(array_intersect($declared, $abilities))->toBe([]);
})->with(array_merge($resources, $managers));

it('applies both concerns everywhere they belong, so a new screen cannot arrive open', function (): void {
    foreach ([ShopResource::class, ListingResource::class] as $resource) {
        expect(in_array(DeniesUnpublishedResourceAbilities::class, class_uses($resource), true))->toBeTrue();
    }

    foreach ([ListingsRelationManager::class, HandoffsRelationManager::class, HistoryRelationManager::class] as $manager) {
        expect(in_array(DeniesUnpublishedRelationAbilities::class, class_uses($manager), true))->toBeTrue();
    }
});
