<?php

namespace App\Http\Controllers\Api\V1;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\Accommodation;
use App\Models\RoomAvailability;
use App\Http\Requests\CheckAvailabilityRequest;
use Illuminate\Routing\Controller as BaseController;

class AccommodationAvailabilityController extends BaseController
{
    public function check(CheckAvailabilityRequest $request, int|string $id): JsonResponse
    {
        $accommodation = Accommodation::findOrFail($id);

        $checkin  = Carbon::parse($request->checkin);
        $checkout = Carbon::parse($request->checkout);
        $nights   = $checkin->diffInDays($checkout);

        $roomTypeIds = $accommodation->roomTypes()->pluck('id');

        // No room types configured → always allow inquiry
        if ($roomTypeIds->isEmpty()) {
            return $this->availableResponse($nights, $id, $request->checkin, $request->checkout, (int) $request->adults, (int) ($request->children ?? 0));
        }

        // For each night in range, check if at least one room type has availability
        $period = CarbonPeriod::create($checkin, $checkout->copy()->subDay());

        foreach ($period as $date) {
            $dateStr = $date->toDateString();

            $hasRecord = RoomAvailability::whereIn('room_type_id', $roomTypeIds)
                ->whereDate('date', $dateStr)
                ->exists();

            // Only block if records exist and all are closed/unavailable
            if ($hasRecord) {
                $hasOpen = RoomAvailability::whereIn('room_type_id', $roomTypeIds)
                    ->whereDate('date', $dateStr)
                    ->where('available', '>', 0)
                    ->where('closed', false)
                    ->exists();

                if (!$hasOpen) {
                    return response()->json(['available' => false], 200);
                }
            }
        }

        return $this->availableResponse($nights, $id, $request->checkin, $request->checkout, (int) $request->adults, (int) ($request->children ?? 0));
    }

    private function availableResponse(int $nights, int|string $accommodationId, string $checkin, string $checkout, int $adults, int $children): JsonResponse
    {
        $token = (string) Str::uuid();

        Cache::put("availability_token:{$token}", [
            'accommodation_id' => (string) $accommodationId,
            'checkin'          => $checkin,
            'checkout'         => $checkout,
            'adults'           => $adults,
            'children'         => $children,
        ], now()->addMinutes(15));

        return response()->json([
            'available'  => true,
            'token'      => $token,
            'nights'     => $nights,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 200);
    }
}
