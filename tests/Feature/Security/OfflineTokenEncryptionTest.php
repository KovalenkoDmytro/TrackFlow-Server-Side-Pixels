<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\ShopTokenCipher;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Osiset\ShopifyApp\Contracts\Commands\Shop as IShopCommand;
use Osiset\ShopifyApp\Objects\Values\AccessToken;
use Osiset\ShopifyApp\Objects\Values\ShopDomain;

function rawPassword(User|int $shop): ?string
{
    return DB::table('users')->where('id', $shop instanceof User ? $shop->getKey() : $shop)->value('password');
}

it('stores the offline token encrypted and returns plaintext to the app and the package', function (): void {
    $shop = User::factory()->create(['password' => 'shpat_plain0123456789']);

    expect(rawPassword($shop))->not->toContain('shpat_')
        ->and(ShopTokenCipher::isEncrypted((string) rawPassword($shop)))->toBeTrue()
        ->and(Crypt::decryptString((string) rawPassword($shop)))->toBe('shpat_plain0123456789')
        ->and($shop->fresh()->password)->toBe('shpat_plain0123456789')
        ->and($shop->fresh()->getAccessToken()->toNative())->toBe('shpat_plain0123456789')
        ->and($shop->fresh()->hasOfflineAccess())->toBeTrue();
});

it('encrypts tokens written by the shopify package (install and token exchange)', function (): void {
    $command = app(IShopCommand::class);
    $id = $command->make(ShopDomain::fromNative('enc-shop.myshopify.com'), AccessToken::fromNative('shp'.'at_first'));
    expect(rawPassword($id->toNative()))->not->toContain('shp'.'at_first');

    $command->setAccessToken($id, AccessToken::fromNative('shpat_second'), 'refresh-plain', now()->addHour(), now()->addDays(90));
    $shop = User::query()->findOrFail($id->toNative());

    expect(rawPassword($shop))->not->toContain('shpat_second')
        ->and($shop->password)->toBe('shpat_second')
        ->and($shop->getAccessToken()->toNative())->toBe('shpat_second');
});

it('keeps empty tokens empty (uninstalled shops)', function (): void {
    $shop = User::factory()->create(['password' => '']);

    expect(rawPassword($shop))->toBe('')->and($shop->fresh()->password)->toBe('')
        ->and($shop->fresh()->hasOfflineAccess())->toBeFalse();
});

it('reads legacy plaintext tokens until they are encrypted', function (): void {
    $shop = User::factory()->create();
    DB::table('users')->where('id', $shop->getKey())->update(['password' => 'shpat_legacy']);

    expect($shop->fresh()->password)->toBe('shpat_legacy');
});

it('never double-encrypts', function (): void {
    $once = ShopTokenCipher::encrypt('shpat_x');

    expect(ShopTokenCipher::encrypt($once))->toBe($once)->and(ShopTokenCipher::decrypt($once))->toBe('shpat_x');
});

it('returns null instead of ciphertext when the value cannot be decrypted', function (): void {
    $foreign = (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString('shpat_other_key');

    expect(ShopTokenCipher::isEncrypted($foreign))->toBeTrue()->and(ShopTokenCipher::decrypt($foreign))->toBeNull();
});

it('encrypts existing plaintext rows idempotently with a dry run', function (): void {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $c = User::factory()->create(['password' => 'shp'.'at_already']);
    DB::table('users')->where('id', $a->getKey())->update(['password' => 'shp'.'at_a']);
    DB::table('users')->where('id', $b->getKey())->update(['password' => '']);
    $encryptedC = rawPassword($c);

    $this->artisan('shopify:encrypt-offline-tokens --dry-run')->expectsOutputToContain('Would encrypt 1 plaintext token(s); 1 already encrypted; 1 empty.')->assertSuccessful();
    expect(rawPassword($a))->toBe('shp'.'at_a');

    $this->artisan('shopify:encrypt-offline-tokens')->expectsOutputToContain('Encrypted 1 plaintext token(s)')->assertSuccessful();
    expect(rawPassword($a))->not->toBe('shp'.'at_a')
        ->and($a->fresh()->password)->toBe('shp'.'at_a')
        ->and(rawPassword($b))->toBe('')
        ->and(rawPassword($c))->toBe($encryptedC);

    $this->artisan('shopify:encrypt-offline-tokens')->expectsOutputToContain('Encrypted 0 plaintext token(s); 2 already encrypted')->assertSuccessful();
});

it('still authenticates API requests with an encrypted token', function (): void {
    $shop = User::factory()->create(['password' => 'shp'.'at_api']);

    $this->withToken($this->shopifySessionToken($shop))->getJson('/api/shop-status')->assertOk();
});
