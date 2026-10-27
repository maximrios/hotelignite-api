<?php

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso interno al staff: entró un prospecto por la landing. El `reply-to` es
 * el prospecto, para contestarle directo desde el mail.
 */
class LeadReceivedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** Backoff escalonado entre reintentos, en segundos: 1 min, 5 min, 15 min. */
    public array $backoff = [60, 300, 900];

    public function __construct(public Lead $lead) {}

    public function build(): self
    {
        $isDemo = $this->lead->type === Lead::TYPE_DEMO;

        return $this->subject(($isDemo ? 'Pedido de demo' : 'Alta desde la landing').": {$this->lead->name}")
            ->replyTo($this->lead->email, $this->lead->name)
            ->markdown('emails.lead-received', [
                'isDemo' => $isDemo,
                'lead' => $this->lead,
            ]);
    }
}
