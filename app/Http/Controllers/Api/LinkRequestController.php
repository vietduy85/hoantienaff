<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LinkRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LinkRequestController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $link = LinkRequest::findOrFail($id);

        if ($link->user_id !== $request->user()?->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'id' => $link->id,
            'status' => $link->status,
            'original_url' => $link->original_url,
            'affiliate_url' => $link->affiliate_url,
            'estimated_cashback' => $link->estimated_cashback,
            'user_estimated_cashback' => $link->user_estimated_cashback,
            'cashback_rate' => $link->cashback_rate,
            'platform' => $link->platform,
            'created_at' => $link->created_at,
        ]);
    }
}
