<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

class CustomerRegistrationCodeMail extends Mailable
{
    public function __construct(
        #[SensitiveParameter]
        public readonly string $code,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirma tu correo en TRAIGO');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.customer-registration-code');
    }
}
