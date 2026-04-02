<?php

namespace App\Mail;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Team $team) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your team is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.team-created',
            with: [
                'teamName' => $this->team->name,
                'teamUrl' => route('teams.show', $this->team),
            ],
        );
    }
}
