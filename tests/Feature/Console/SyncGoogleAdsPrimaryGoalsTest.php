<?php

declare(strict_types=1);

use App\Enums\Platform;
use App\Models\PlatformIntegration;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function primaryGoalsIntegration(): PlatformIntegration
{
    return PlatformIntegration::query()->create([
        'user_id' => User::factory()->create()->getKey(),
        'platform' => Platform::GoogleAds,
        'active' => true,
        'credentials' => json_encode(['customer_id' => '123-456-7890', 'oauth' => ['client_id' => 'id', 'client_secret' => 'secret', 'refresh_token' => 'refresh']]),
    ]);
}

function fakePrimaryGoalsApi(): void
{
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
        'https://googleads.googleapis.com/v24/customers/1234567890/googleAds:search' => Http::response(['results' => [
            ['conversionAction' => ['resourceName' => 'customers/1234567890/conversionActions/1', 'name' => 'TF - purchase', 'primaryForGoal' => true]],
            ['conversionAction' => ['resourceName' => 'customers/1234567890/conversionActions/2', 'name' => 'TF - view_item', 'primaryForGoal' => true]],
            // Unset primary_for_goal means Google's default (true).
            ['conversionAction' => ['resourceName' => 'customers/1234567890/conversionActions/3', 'name' => 'TF - add_to_cart']],
            ['conversionAction' => ['resourceName' => 'customers/1234567890/conversionActions/4', 'name' => 'TF - begin_checkout', 'primaryForGoal' => false]],
            ['conversionAction' => ['resourceName' => 'customers/1234567890/conversionActions/9', 'name' => 'Website purchase', 'primaryForGoal' => true]],
        ]]),
        'https://googleads.googleapis.com/v24/customers/1234567890/conversionActions:mutate' => Http::response(['results' => [[]]]),
    ]);
}

describe('google-ads:sync-primary-goals', function (): void {
    it('only lists changes with --dry-run', function (): void {
        primaryGoalsIntegration();
        fakePrimaryGoalsApi();

        $this->artisan('google-ads:sync-primary-goals', ['--dry-run' => true])
            ->expectsOutputToContain('would set secondary')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/conversionActions:mutate'));
    });

    it('makes primary non-purchase TrackFlow actions secondary and leaves Purchase and other actions alone', function (): void {
        primaryGoalsIntegration();
        fakePrimaryGoalsApi();

        $this->artisan('google-ads:sync-primary-goals')->assertSuccessful();

        $updates = [];
        Http::assertSent(function ($request) use (&$updates): bool {
            if (str_ends_with($request->url(), '/conversionActions:mutate')) {
                $operation = $request->data()['operations'][0];
                expect($operation['update_mask'])->toBe('primary_for_goal')
                    ->and($operation['update']['primary_for_goal'])->toBeFalse();
                $updates[] = $operation['update']['resource_name'];
            }

            return true;
        });

        expect($updates)->toBe([
            'customers/1234567890/conversionActions/2',
            'customers/1234567890/conversionActions/3',
        ]);
    });

    it('never promotes a secondary Purchase action', function (): void {
        primaryGoalsIntegration();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token']),
            'https://googleads.googleapis.com/v24/customers/1234567890/googleAds:search' => Http::response(['results' => [
                ['conversionAction' => ['resourceName' => 'customers/1234567890/conversionActions/1', 'name' => 'TF - purchase', 'primaryForGoal' => false]],
            ]]),
        ]);

        $this->artisan('google-ads:sync-primary-goals')
            ->expectsOutputToContain('left unchanged')
            ->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/conversionActions:mutate'));
    });

    it('fails when no active Google Ads integration matches', function (): void {
        $this->artisan('google-ads:sync-primary-goals', ['integration' => 999])->assertFailed();
    });
});
