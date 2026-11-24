<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Send to support" reports from issue cards: one open report per shop + platform + error code.
 * details holds the snapshot that was emailed (counts, breakdown, sample failures).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 32);
            $table->string('code', 100);
            $table->text('note')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->json('details')->nullable();
            $table->timestamp('reported_at');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'platform', 'code', 'closed_at'], 'sr_user_platform_code_closed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_reports');
    }
};
