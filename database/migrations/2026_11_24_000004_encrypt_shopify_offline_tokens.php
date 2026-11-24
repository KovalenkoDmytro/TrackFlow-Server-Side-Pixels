<?php

declare(strict_types=1);

use App\Console\Commands\EncryptOfflineTokens;
use App\Support\ShopTokenCipher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.password holds the Shopify offline access token. Widen it to TEXT (an encrypted
 * token is ~260 characters) and encrypt the existing plaintext values (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('password')->nullable()->change();
        });

        EncryptOfflineTokens::encryptAll();
    }

    public function down(): void
    {
        DB::table('users')->select(['id', 'password'])->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                if (is_string($row->password) && ShopTokenCipher::isEncrypted($row->password)) {
                    DB::table('users')->where('id', $row->id)->update(['password' => ShopTokenCipher::decrypt($row->password) ?? '']);
                }
            }
        });
    }
};
