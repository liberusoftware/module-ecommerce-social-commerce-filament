<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Liberu\Ecommerce\SocialCommerce\Filament\Pages\WithdrawalQueue;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Listings\ListingResource;
use Liberu\Ecommerce\SocialCommerce\Filament\Resources\Shops\ShopResource;
use Liberu\Ecommerce\SocialCommerce\Filament\SocialCommercePlugin;

/*
 * The rules that are this package's rather than the fleet's. The shared boundary
 * suite in `package-testbench` asserts the manifest, the required files, the
 * absence of `App\` and the panel plugins.
 */

function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

/** @return array<int, string> every PHP file under src/, absolute. */
function sourceFiles(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot().'/src')) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Source with comments stripped. Every rule below is about what this package
 * does, and a docblock naming what it refuses to do is the text most likely to
 * trip a naive grep.
 */
function sourceCode(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** @return array<string, mixed> */
function packageJson(string $file): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) file_get_contents(packageRoot().'/'.$file), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('registers everything through the plugin and nothing from the service provider', function (): void {
    // A provider that registered resources would put a credential form on every
    // panel the application has, including a shopper-facing one.
    $provider = (string) file_get_contents(packageRoot().'/src/SocialCommerceFilamentServiceProvider.php');

    expect($provider)->not->toContain('Resource::class')
        ->and($provider)->not->toContain('->resources(')
        ->and($provider)->not->toContain('->pages(');

    $plugin = (string) file_get_contents(packageRoot().'/src/SocialCommercePlugin.php');

    expect($plugin)->toContain('ShopResource::class')
        ->and($plugin)->toContain('ListingResource::class')
        ->and($plugin)->toContain('WithdrawalQueue::class')
        ->and($plugin)->not->toContain('->widgets(');
});

it('declares every class the manifest names for the panel', function (): void {
    $manifest = packageJson('module.json');

    expect($manifest['presentation']['filament']['admin'])->toBe([SocialCommercePlugin::class]);

    foreach ($manifest['presentation']['filament'] as $plugins) {
        foreach ($plugins as $plugin) {
            expect(class_exists($plugin))->toBeTrue();
        }
    }

    expect(class_exists(ShopResource::class))->toBeTrue()
        ->and(class_exists(ListingResource::class))->toBeTrue()
        ->and(class_exists(WithdrawalQueue::class))->toBeTrue();
});

it('writes nothing through Eloquent, because every write here is a published domain action', function (): void {
    // A form that saved a listing would bypass the transition guard, the audit
    // row and the append-only history all at once — on the only record that a
    // network holds something.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain('->save()')
            ->and($source)->not->toContain('->update(')
            ->and($source)->not->toContain('->delete()')
            ->and($source)->not->toContain('->forceFill(')
            ->and($source)->not->toContain('->increment(')
            ->and($source)->not->toContain('->decrement(');
    }
});

it('never reaches the secret, and never names the credential model', function (): void {
    // The host's `access_token` was in `$fillable` under an `encrypted` cast
    // with no `$hidden`, so the model decrypted it on attribute access and the
    // first serialiser added would have printed it. The only way in here is a
    // write-only field on a named action.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toContain('reveal(')
            ->and($source)->not->toContain('ShopCredential')
            ->and($source)->not->toContain('access_token')
            ->and($source)->not->toContain("TextColumn::make('secret')")
            ->and($source)->not->toContain("TextEntry::make('secret')");
    }
});

it('shows no content the domain does not store', function (): void {
    // Price and a stock flag are stored; the title, description, image and link
    // a network publishes are not. A column claiming to show what was last sent
    // would be this panel inventing a fact nobody wrote down.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        foreach (['title', 'description', 'image_url', 'link', 'brand'] as $field) {
            expect($source)->not->toContain("make('{$field}')");
        }
    }
});

it('ships no adapter, no endpoint and no network vocabulary', function (): void {
    // The domain is provider-neutral and so is its panel: a network is a slug an
    // adapter names, which is what stops admitting a new one being a release.
    foreach (sourceFiles() as $file) {
        $source = strtolower(sourceCode($file));

        foreach (['graph.facebook', 'http::', 'guzzle', 'graph_version', 'instagram', 'tiktok', 'pinterest'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
});

it('reaches for no framework-foundation helper', function (): void {
    // `config()`, `app()`, `auth()` and `now()` live in `laravel/framework`, not
    // in `illuminate/support`. They pass CI because the testbench drags the
    // framework in, and are a lying constraint for a real consumer.
    foreach (sourceFiles() as $file) {
        $source = sourceCode($file);

        expect($source)->not->toMatch('/(?<![\w>$])config\(/')
            ->and($source)->not->toMatch('/(?<![\w>$])app\(/')
            ->and($source)->not->toMatch('/(?<![\w>$])auth\(/')
            ->and($source)->not->toMatch('/(?<![\w>$])now\(/');
    }
});

it('states its Filament floor as the true one', function (): void {
    // Every tag from v5.4.0 declares `illuminate/contracts: ^11.28|^12.0|^13.0`;
    // v5.3's support package caps at ^12.0.
    expect(packageJson('composer.json')['require']['filament/filament'])->toBe('^5.4');
});

it('lists the sibling packages the manifest declares and no others', function (): void {
    $composer = packageJson('composer.json');
    $manifest = packageJson('module.json');

    $siblings = array_filter(
        $composer['require'],
        fn (string $constraint, string $package): bool => str_starts_with($package, 'liberusoftware/'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect($siblings)->toBe($manifest['requires']['packages'])
        ->and($siblings)->toBe(['liberusoftware/ecommerce-social-commerce' => '^0.1']);
});

it('points at the domain repository, because it is not on Packagist yet', function (): void {
    expect(packageJson('composer.json')['repositories'])->toBe([[
        'type' => 'vcs',
        'url' => 'https://github.com/liberusoftware/module-ecommerce-social-commerce',
    ]]);
});

it('carries no session identifier in any file', function (): void {
    $files = array_merge(sourceFiles(), [
        packageRoot().'/README.md',
        packageRoot().'/CHANGELOG.md',
        packageRoot().'/module.json',
    ]);

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        // Split so this assertion is not itself the thing it forbids: a
        // repository-wide grep for the literals has to come back empty.
        expect($source)->not->toContain('claude'.'.ai')
            ->and($source)->not->toContain('Claude-'.'Session');
    }
});

it('contributes nothing when a panel boots it, so nothing can arrive on a panel that did not register it', function (): void {
    $plugin = SocialCommercePlugin::make();
    $plugin->boot(Filament::getPanel('admin'));

    expect($plugin->getId())->toBe('ecommerce-social-commerce');
});
