<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use SensitiveParameter;

/** El enlace para que un cliente ponga una contraseña nueva. */
class CustomerPasswordResetMail extends Mailable
{
    public function __construct(
        public readonly string $customerName,
        #[SensitiveParameter]
        public readonly string $resetUrl,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Recupera tu contraseña de Traigo');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.customer-password-reset');
    }
}
