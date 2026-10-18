<?php

namespace App\Http\Controllers;

use App\Services\ShopeeFashionAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hai hành động của thông báo "cào xu" trên trang chủ:
 *  - acknowledge: đánh dấu tất cả đơn thời trang hiện có là "đã biết".
 *  - disable: tắt vĩnh viễn thông báo cho user.
 *
 * Chỉ tác động lên đơn của chính user đang đăng nhập.
 */
class ShopeeFashionAlertController extends Controller
{
    public function __construct(
        private readonly ShopeeFashionAlertService $service,
    ) {}

    public function acknowledge(Request $request): JsonResponse
    {
        $count = $this->service->acknowledge($request->user());

        return response()->json([
            'success' => true,
            'acknowledged' => $count,
            'remaining' => $this->service->unseenCount($request->user()),
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $this->service->disable($request->user());

        return response()->json([
            'success' => true,
            'disabled' => true,
        ]);
    }
}
