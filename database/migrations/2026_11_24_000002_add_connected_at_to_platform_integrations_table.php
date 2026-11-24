<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the merchant last saved credentials that passed the connection test (connect or
 * reconnect). updated_at cannot be used: every upload saves the integration. Existing rows
 * stay null until their next reconnect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_integrations', function (Blueprint $table): void {
            $table->timestamp('connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_integrations', function (Blueprint $table): void {
            $table->dropColumn('connected_at');
        });
    }
};
