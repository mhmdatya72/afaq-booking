<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookingService) {}

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $result = $this->bookingService->create(
            $request->user(),
            $request->validated(),
            trim($request->header('Idempotency-Key')),
        );

        return response()->json($result['body'], $result['status'])->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $booking = $this->bookingService->cancel($request->user(), $id);

        return response()->json(['data' => $booking]);
    }
}
