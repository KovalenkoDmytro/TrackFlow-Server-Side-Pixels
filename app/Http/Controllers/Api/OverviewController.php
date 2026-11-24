<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\GetTrackingOverview;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\OverviewRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Home page overview: health score, KPIs, daily series and delivery issues
 * for the last 7, 30 or 90 days (default 30), ending today.
 */
final class OverviewController extends Controller
{
    public function __construct(private readonly GetTrackingOverview $getOverview) {}

    public function index(OverviewRequest $request): JsonResponse
    {
        $shop = $request->user();

        if ($shop === null) {
            return response()->json([
                'message' => 'Shopify session token rejected: shop_not_installed.',
                'code' => 'shop_not_installed',
            ], 401);
        }

        $timezone = config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $days = (int) $request->input('days', 30);
        $retention = (int) config('tracking.retention_days', 90);
        $days = max(1, min($days, $retention));

        $end = $now->endOfDay();
        $start = $now->startOfDay()->subDays($days - 1);

        return response()->json($this->getOverview->handle($shop, $start, $end, $now));
    }
}
