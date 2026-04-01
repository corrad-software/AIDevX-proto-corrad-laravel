<?php

namespace App\Mail;

use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InAppNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public InAppNotification $notification,
        public User $user,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[AFSA] '.$this->notification->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.in-app-notification',
        );
    }
}
