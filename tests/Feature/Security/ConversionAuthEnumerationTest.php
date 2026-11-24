<?php

declare(strict_types=1);

use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

function enumPayload(string $domain, string $secret): array
{
    return ['shop_domain' => $domain, 'tracking_secret' => $secret, 'event' => 'page_view'];
}

it('answers unknown shops, uninstalled shops and wrong secrets with the same 401', function (): void {
    $installed = User::factory()->create(['name' => 'real.myshopify.com', 'tracking_secret' => 'right']);
    $uninstalled = User::factory()->create(['name' => 'gone.myshopify.com', 'tracking_secret' => 'right']);
    DB::table('users')->where('id', $uninstalled->getKey())->update(['password' => '']);

    $responses = [
        $this->postJson('/api/conversions', enumPayload('nobody.myshopify.com', 'right')),
        $this->postJson('/api/conversions', enumPayload('real.myshopify.com', 'wrong')),
        $this->postJson('/api/conversions', enumPayload('gone.myshopify.com', 'right')),
    ];

    foreach ($responses as $response) {
        $response->assertUnauthorized()->assertJsonPath('message', 'Unauthorized.');
    }
    expect(TrackingEvent::query()->count())->toBe(0);

    $this->postJson('/api/conversions', enumPayload('real.myshopify.com', 'right'))->assertOk();
});
