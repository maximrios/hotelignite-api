<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Requests\Admin\NearbyEventsRequest;
use App\Models\Accommodation;
use App\Models\Event;
use App\Repositories\Contracts\EventInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;

/**
 * "Agenda cercana": eventos a menos de `radius_m` del alojamiento entre `from`
 * y `to` (por defecto, los próximos 30 días). Tenencia vía `AccommodationPolicy@view`.
 */
class AccommodationNearbyEventController extends BaseController
{
    use AuthorizesRequests;

    private const DEFAULT_RADIUS_M = 15000;

    private const DEFAULT_DAYS = 30;

    private const DEFAULT_LIMIT = 20;

    public function __construct(private EventInterface $events) {}

    public function index(NearbyEventsRequest $request, Accommodation $accommodation): JsonResponse
    {
        $this->authorize('view', $accommodation);

        $from = $request->filled('from')
            ? CarbonImmutable::parse((string) $request->input('from'))
            : Event::today();
        $to = $request->filled('to')
            ? CarbonImmutable::parse((string) $request->input('to'))
            : $from->addDays(self::DEFAULT_DAYS);

        return response()->json(['data' => $this->events->nearby(
            $accommodation,
            $from,
            $to,
            $request->integer('radius_m', self::DEFAULT_RADIUS_M),
            $request->integer('limit', self::DEFAULT_LIMIT),
        )]);
    }
}
