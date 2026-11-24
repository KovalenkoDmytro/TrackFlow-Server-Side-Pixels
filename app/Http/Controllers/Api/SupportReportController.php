<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Support\ReportDeliveryIssue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\SupportReportRequest;
use Illuminate\Http\JsonResponse;

/**
 * "Send to support" for a delivery issue (platform + error code) of the current shop.
 */
final class SupportReportController extends Controller
{
    public function store(SupportReportRequest $request, ReportDeliveryIssue $report): JsonResponse
    {
        $note = $request->input('note');
        $result = $report->handle(
            $request->user(),
            (string) $request->input('platform'),
            (string) $request->input('code'),
            is_string($note) ? $note : null,
        );

        return response()->json([
            'platform' => $result['report']->platform,
            'code' => $result['report']->code,
            'reported_at' => $result['report']->reported_at->toIso8601String(),
            'sent' => $result['sent'],
        ], $result['report']->wasRecentlyCreated ? 201 : 200);
    }
}
