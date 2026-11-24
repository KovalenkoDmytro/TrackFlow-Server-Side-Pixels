<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ShopTokenCipher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Encrypts Shopify offline tokens still stored as plaintext in users.password. Idempotent:
 * already-encrypted and empty values are skipped. Also run by the 2026_11_24_000004 migration.
 */
final class EncryptOfflineTokens extends Command
{
    protected $signature = 'shopify:encrypt-offline-tokens {--dry-run : Only count the plaintext tokens}';

    protected $description = 'Encrypt Shopify offline access tokens stored as plaintext (idempotent)';

    public function handle(): int
    {
        $result = self::encryptAll((bool) $this->option('dry-run'));

        $this->info(sprintf(
            '%s %d plaintext token(s); %d already encrypted; %d empty.',
            $this->option('dry-run') ? 'Would encrypt' : 'Encrypted',
            $result['plaintext'],
            $result['encrypted'],
            $result['empty'],
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{plaintext: int, encrypted: int, empty: int}
     */
    public static function encryptAll(bool $dryRun = false): array
    {
        $counts = ['plaintext' => 0, 'encrypted' => 0, 'empty' => 0];

        DB::table('users')->select(['id', 'password'])->orderBy('id')->chunkById(200, function ($rows) use (&$counts, $dryRun): void {
            foreach ($rows as $row) {
                $value = is_string($row->password) ? $row->password : '';

                if ($value === '') {
                    $counts['empty']++;
                } elseif (ShopTokenCipher::isEncrypted($value)) {
                    $counts['encrypted']++;
                } else {
                    $counts['plaintext']++;
                    if (! $dryRun) {
                        DB::table('users')->where('id', $row->id)->where('password', $value)
                            ->update(['password' => ShopTokenCipher::encrypt($value)]);
                    }
                }
            }
        });

        return $counts;
    }
}
