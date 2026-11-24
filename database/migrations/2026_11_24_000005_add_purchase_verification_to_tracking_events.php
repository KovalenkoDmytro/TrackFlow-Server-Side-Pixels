<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases checked against the shop's Shopify order (VerifyPurchase): the verified order id
 * (one tracked purchase per order) and when it was verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('shopify_order_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->index(['user_id', 'shopify_order_id'], 'te_user_shopify_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table): void {
            $table->dropIndex('te_user_shopify_order_index');
            $table->dropColumn(['shopify_order_id', 'verified_at']);
        });
    }
};
