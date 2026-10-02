<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'re invited to join '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invitation',
            with: [
                'acceptUrl' => route('invitations.accept', ['token' => $this->invitation->token]),
                'inviterName' => $this->invitation->invitedBy->name,
                'role' => ucfirst($this->invitation->role->value),
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
