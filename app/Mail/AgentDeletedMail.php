<?php

namespace App\Mail;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentDeletedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $agentName, public Team $team) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Agent removed',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.agent-deleted',
            with: [
                'agentName' => $this->agentName,
                'teamName' => $this->team->name,
                'agentsUrl' => route('agents.index'),
            ],
        );
    }
}
