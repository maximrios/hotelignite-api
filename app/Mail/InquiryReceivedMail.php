<?php

namespace App\Mail;

use App\Models\Accommodation;
use App\Models\Experience;
use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso de consulta nueva. Al hotelero si es sobre un alojamiento (link al PMS),
 * al client si es sobre una experiencia suya (link al portal). Ver
 * docs/accommodation-mcp-plan.md, fase 3.
 */
class InquiryReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Backoff escalonado entre reintentos, en segundos: 1 min, 5 min, 15 min. */
    public array $backoff = [60, 300, 900];

    public function __construct(public Inquiry $inquiry) {}

    public function build(): self
    {
        $this->inquiry->loadMissing('inquirable');
        $inquirable = $this->inquiry->inquirable;

        $about = $inquirable instanceof Accommodation || $inquirable instanceof Experience
            ? (string) $inquirable->name
            : 'tu alojamiento';

        $url = $inquirable instanceof Experience
            ? config('api.clients_portal_url').'/consultas'
            : config('api.pms_url').'/consultas';

        $guest = trim($this->inquiry->name.' '.($this->inquiry->lastname ?? ''));

        return $this->subject("Nueva consulta para {$about}")
            ->markdown('emails.inquiry-received', [
                'about' => $about,
                'guest' => $guest,
                'viaAgent' => $this->inquiry->source === Inquiry::SOURCE_AGENT,
                'phone' => $this->inquiry->phone,
                'email' => $this->inquiry->email,
                'checkin' => $this->inquiry->checkin,
                'checkout' => $this->inquiry->checkout,
                'adults' => (int) $this->inquiry->adults,
                'children' => (int) $this->inquiry->childrens,
                'message' => $this->inquiry->message,
                'url' => $url,
            ]);
    }
}
