<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\V1\InquiryResource;
use App\Mail\InquiryReceivedMail;
use App\Models\Accommodation;
use App\Models\Experience;
use App\Models\Inquiry;
use App\Models\User;
use App\Repositories\Concerns\ScopesToAccommodation;
use App\Repositories\Contracts\InquiryInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** Consultas de viajeros sobre alojamientos o experiencias (docs/experiences-plan.md). */
class InquiryRepository implements InquiryInterface
{
    use ScopesToAccommodation;

    /** Ventana en la que una consulta repetida del agente no se duplica. */
    private const AGENT_DEDUPE_MINUTES = 30;

    public function all(Request $request)
    {
        $type = $request->input('type');

        $inquiries = Inquiry::query()
            ->visibleTo($this->tenant())
            ->with('inquirable')
            ->when(
                $request->filled('accommodation_id'),
                fn ($query) => $query->forAccommodation($request->integer('accommodation_id'))
            )
            ->when(
                $request->filled('experience_id'),
                fn ($query) => $query->forExperience($request->integer('experience_id'))
            )
            ->when(
                is_string($type) && isset(Inquiry::TYPES[$type]),
                fn ($query) => $query->where('inquirable_type', Inquiry::TYPES[$type])
            )
            ->when(
                in_array($request->input('status'), Inquiry::STATUSES, true),
                fn ($query) => $query->where('status', $request->input('status'))
            )
            ->orderBy('created_at', 'desc')
            ->paginate(min($request->integer('per_page', 25), 100));

        return InquiryResource::collection($inquiries);
    }

    /** Alta legacy por `v1` (fechas d/m/Y). */
    public function store($request)
    {
        $checkin = $request->checkin
            ? Carbon::createFromFormat('d/m/Y', $request->checkin)->format('Y-m-d')
            : null;
        $checkout = $request->checkout
            ? Carbon::createFromFormat('d/m/Y', $request->checkout)->format('Y-m-d')
            : null;

        $inquiry = new Inquiry;
        if ($request->filled('experience_id')) {
            $inquiry->inquirable_type = Experience::class;
            $inquiry->inquirable_id = (int) $request->experience_id;
        } elseif ($request->filled('accommodation_id')) {
            $inquiry->inquirable_type = Accommodation::class;
            $inquiry->inquirable_id = (int) $request->accommodation_id;
        }
        $inquiry->name = $request->name;
        $inquiry->lastname = $request->lastname;
        $inquiry->email = $request->email;
        $inquiry->phone = $request->phone;
        $inquiry->adults = $request->adults;
        $inquiry->childrens = $request->childrens;
        $inquiry->checkin = $checkin;
        $inquiry->checkout = $checkout;
        $inquiry->message = $request->message;
        $inquiry->save();

        $this->notify($inquiry);

        return new InquiryResource($inquiry);
    }

    public function storeFromPortal(Model $inquirable, int $clientId, array $data): InquiryResource
    {
        $inquiry = new Inquiry;
        $inquiry->inquirable()->associate($inquirable);
        $inquiry->client_id = $clientId;
        $inquiry->source = 'portal';
        $inquiry->name = $data['name'];
        $inquiry->lastname = $data['lastname'];
        $inquiry->email = $data['email'];
        $inquiry->phone = $data['phone'];
        $inquiry->adults = (int) $data['adults'];
        $inquiry->childrens = (int) ($data['childrens'] ?? 0);
        $inquiry->checkin = $data['checkin'] ?? null;
        $inquiry->checkout = $data['checkout'] ?? null;
        $inquiry->message = $data['message'] ?? null;
        $inquiry->save();

        $this->notify($inquiry);

        return new InquiryResource($inquiry->load('inquirable'));
    }

    public function storeFromAgent(Accommodation $accommodation, array $data): Inquiry
    {
        // El agente puede reintentar una tool call: la misma consulta (mismo
        // teléfono y llegada) dentro de la ventana devuelve la que ya existe.
        $existing = Inquiry::query()
            ->forAccommodation((int) $accommodation->id)
            ->where('source', Inquiry::SOURCE_AGENT)
            ->where('phone', $data['phone'])
            ->whereDate('checkin', $data['checkin'])
            ->where('created_at', '>=', now()->subMinutes(self::AGENT_DEDUPE_MINUTES))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $inquiry = new Inquiry;
        $inquiry->inquirable()->associate($accommodation);
        $inquiry->source = Inquiry::SOURCE_AGENT;
        $inquiry->name = $data['name'];
        $inquiry->lastname = $data['lastname'] ?? null;
        $inquiry->email = $data['email'] ?? null;
        $inquiry->phone = $data['phone'];
        $inquiry->adults = (int) $data['adults'];
        $inquiry->childrens = (int) ($data['children'] ?? 0);
        $inquiry->checkin = $data['checkin'];
        $inquiry->checkout = $data['checkout'] ?? null;
        $inquiry->message = $data['message'] ?? null;
        $inquiry->save();

        $this->notify($inquiry);

        return $inquiry;
    }

    /**
     * Avisa por mail que entró una consulta: a los usuarios de la cuenta que
     * pueden ver el alojamiento, o a los del client dueño de la experiencia.
     */
    private function notify(Inquiry $inquiry): void
    {
        $inquiry->loadMissing('inquirable');
        $inquirable = $inquiry->inquirable;

        $recipients = match (true) {
            $inquirable instanceof Accommodation && $inquirable->account_id !== null => User::query()
                ->where('user_type', User::TYPE_ACCOUNT)
                ->where('account_id', $inquirable->account_id)
                // Un usuario acotado a otros alojamientos de la cuenta no se entera.
                ->where(fn ($q) => $q->whereDoesntHave('accommodations')
                    ->orWhereHas('accommodations', fn ($a) => $a->where('accommodations.id', $inquirable->id))),
            $inquirable instanceof Experience && $inquirable->client_id !== null => User::query()
                ->where('user_type', User::TYPE_CLIENT)
                ->where('client_id', $inquirable->client_id),
            default => null,
        };

        $emails = $recipients?->whereNotNull('email')->pluck('email')->filter()->unique()->values()->all() ?? [];

        foreach ($emails as $email) {
            Mail::to($email)->queue(new InquiryReceivedMail($inquiry));
        }
    }

    public function setStatus(Inquiry $inquiry, string $status): InquiryResource
    {
        $inquiry->status = $status;
        $inquiry->save();

        return new InquiryResource($inquiry->load('inquirable'));
    }
}
