<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\ResolveDeliveryIssue;
use App\Actions\Analytics\UnresolveDeliveryIssue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\DeliveryIssueResolutionRequest;
use Illuminate\Http\JsonResponse;

/**
 * Mark a delivery issue (platform + error code) as resolved for the current shop, or undo it.
 */
final class DeliveryIssueResolutionController extends Controller
{
    public function store(DeliveryIssueResolutionRequest $request, ResolveDeliveryIssue $resolve): JsonResponse
    {
        $resolution = $resolve->handle($request->user(), (string) $request->input('platform'), (string) $request->input('code'));

        return response()->json([
            'platform' => $resolution->platform,
            'code' => $resolution->code,
            'resolved_at' => $resolution->resolved_at->toIso8601String(),
        ], 201);
    }

    public function destroy(DeliveryIssueResolutionRequest $request, UnresolveDeliveryIssue $unresolve): JsonResponse
    {
        $removed = $unresolve->handle($request->user(), (string) $request->input('platform'), (string) $request->input('code'));

        return response()->json(['removed' => $removed]);
    }
}
