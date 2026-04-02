<?php

namespace App\Mail;

use App\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentActiveMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Agent $agent) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your agent is live',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.agent-active',
            with: [
                'agentName' => $this->agent->name,
                'agentUrl' => route('agents.show', $this->agent),
            ],
        );
    }
}
