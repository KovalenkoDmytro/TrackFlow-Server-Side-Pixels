<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Encryption of the Shopify offline access token that the shopify package stores in
 * users.password. Values written before encryption was added are plaintext and are still
 * read as is until shopify:encrypt-offline-tokens (or its migration) converts them.
 */
final class ShopTokenCipher
{
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '' || self::isEncrypted($plain)) {
            return $plain;
        }

        return Crypt::encryptString($plain);
    }

    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || ! self::isEncrypted($stored)) {
            return $stored;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException $e) {
            // Wrong APP_KEY or corrupt value: never hand ciphertext to Shopify. The shop is
            // treated as not installed and goes through OAuth again, which stores a new token.
            Log::error('shopify.offline_token_decrypt_failed', ['reason' => $e->getMessage()]);

            return null;
        }
    }

    /** Laravel encrypter payload: base64 JSON with iv, value and mac. */
    public static function isEncrypted(string $value): bool
    {
        if (strlen($value) < 100) {
            return false;
        }

        $json = base64_decode($value, true);
        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
