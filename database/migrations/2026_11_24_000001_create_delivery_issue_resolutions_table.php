<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A merchant marked a delivery issue (platform + error code) as resolved. Failures of that
 * code on that platform up to resolved_at are left out of issue cards and the health score.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_issue_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('code', 100);
            $table->timestamp('resolved_at');
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'code'], 'dir_user_platform_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_issue_resolutions');
    }
};
